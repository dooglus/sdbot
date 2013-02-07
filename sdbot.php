<?php

/*
Make the MIN_BET and MAX_BET a percentage/fraction of the current balance

If LOSE! use this same amount multiplied until WIN! OR MAX_BET reached

If WIN! send a fraction of win amount to "1XYZ1" address 

If MAX_BET Reached, start over

Stop after X amount sent to "1XYZ1" address
OR count_won grater than XYZ2 
OR after a WIN! and the ratio of win loses is grater than XYZ3 and a minimum of XYZ bets have been played

Always wait for confirmed balance to avoid the larger fees.(no rush to lose everything)
*/

require_once('jsonRPCClient.php');

define('RPCUSER',     'myuser');
define('RPCPASSWORD', 'mypass');

// address to stash our winnings to keep them safe
define('STASH_ADDRESS',                 '1Doog7asLrYah3yeUppBVj8nUYnFkmXm2N');

// set to false (no quotes) if your wallet isn't encrypted, or set to
// "prompt" if you want to be prompted each time you run the script
// (this is the best thing to do - it's dangerous to save your
// passphrase in a script)
define('WALLET_PASSPHRASE',             'prompt');

// what percentage of our winnings to send to the stash address (this
// is the net winnings, right?  so if we lose 1, lose 2, win 4 then we
// stash some percentage of 1 coin, not of the 4 coins we won in the end?)
// use 0 to never stash winnings away.
define('STASH_PERCENTAGE',              90); // %

// save up stashed amounts in the wallet until they reach this threshold (saves network fees)
// set to 0 if you want to actually transfer stashed coins after every win, no matter how small
define('STASH_THRESHOLD',               0.025); 

// if this isn't zero, then we will always use this as the starting bet, and will ignore the next setting
define('FIXED_MIN_BET', 0.01);

// what percentage of our balance do we bet as the first bet
define('MIN_BET_AS_BALANCE_PERCENTAGE', 0.9); // %

// if this isn't zero, then we will always use this as the max bet, and will ignore the next setting
define('FIXED_MAX_BET', 10.25);

// what percentage of our balance are we willing to bet in one go
define('MAX_BET_AS_BALANCE_PERCENTAGE', 100); // %

// stop once we've sent this much to the stash address
define('TARGET_WINNINGS',               0.05);

// stop once we've won this many times
define('TARGET_WINS',                   30); 

// stop once ratio wins/losses is greater than this BUT...
define('TARGET_WINS_TO_LOSSES_RATIO',   1.9); 

// ... only if we have placed at least this many bets
define('MIN_BETS_FOR_RATIO_RULE',       10);

// each time we win a bet, the bot will stop and report overall profits if this file exists
define('STOP_ON_WIN_IF_EXISTS',         'stop.txt');

// satoshidice bet address
define('BET_ADDRESS',                   '1dice8EMZmqKvrGE4Qc9bUFf9PX3xaYDp');

// satoshidice minimum bet amount
define('SD_MIN_BET',                    0.01);

// how much to multiply bet by when we lose
define('BET_MULTIPLIER',                2);

// should we wait until we have enough confirmed funds?
define('WAIT_FOR_CONFIRMS',             true);

// set min fee while playing
define('FEE_WHILE_PLAYING',             0.0005);

// set min fee when stashing
define('FEE_WHILE_STASHING',            0);

// set min fee back after playing
define('FEE_AFTER_PLAYING',             0);

// width and precision of BTC formatting
// %11.8f means 11 characters wide, with 8 decimal digits (12.12345678)
define('BTC_FORMAT',                    '%10.6f BTC');

// show min and max bets then quit without betting; so we can check things are sane before playing
define('DRY_RUN',                       false);

define('DEBUG',                         false);

$bitcoin = new jsonRPCClient('http://'.RPCUSER.':'.RPCPASSWORD.'@127.0.0.1:8332/');

function check_bitcoin_connection() {
    global $bitcoin;
    try {
        $bitcoin->getbalance();
    } catch (Exception $e) {
        die("can't connect to bitcoin - did you set rpcuser and rpcpassword in this script and in ~/.bitcoin/bitcoin.conf ?\n");
    }
}

function prompt_silent($prompt = "Enter Password:") {
    if (preg_match('/^win/i', PHP_OS)) {
        $vbscript = sys_get_temp_dir() . 'prompt_password.vbs';
        file_put_contents(
            $vbscript, 'wscript.echo(InputBox("'
            . addslashes($prompt)
            . '", "", "password here"))');
        $command = "cscript //nologo " . escapeshellarg($vbscript);
        $password = rtrim(shell_exec($command));
        unlink($vbscript);
        return $password;
    } else {
        $command = "/usr/bin/env bash -c 'echo OK'";
        if (rtrim(shell_exec($command)) !== 'OK') {
            trigger_error("Can't invoke bash");
            return;
        }
        $command = "/usr/bin/env bash -c 'read -s -p \""
            . addslashes($prompt)
            . "\" mypassword && echo \$mypassword'";
        $password = rtrim(shell_exec($command));
        echo "\n";
        return $password;
    }
}

function set_fee($fee) {
    global $bitcoin;
    $bitcoin->settxfee($fee);
}
    
function get_balance() {
    global $bitcoin;
    return $bitcoin->getbalance('*', 0);
}

// getbalance '*' 1 doesn't work at all well for getting confirmed
// balance, because it includes any unconfirmed change from our bets
// but SD considers unconfirmed change as unconfirmed, and so delays
// processing our bets.  We'd be better off waiting to see which of
// our inputs confirms first, and using those to bet rather than
// betting with unconfirmed change that may not make it into a block
// soon.
function get_confirmed_balance() {
    global $bitcoin;
    $unspent = 0;
    foreach ($bitcoin->listunspent(1) as $tx)
        $unspent += $tx['amount'];
    return $unspent;
}

function unlock_wallet() {
    global $bitcoin, $wallet_passphrase;

    if ($wallet_passphrase) {
        try {
            $bitcoin->walletpassphrase($wallet_passphrase, 60);
        } catch (Exception $e) {
            $message = $e->getMessage();
            $json = json_decode(substr($message, strlen('Request error: ')), true);
            if ($json['code'] == -14) { // "Error: The wallet passphrase entered was incorrect."
                print "\n\n";
                die($json['message'] . "\n");
            }
            if ($json['code'] != -17) // "Error: Wallet is already unlocked."
                print "\n\n" . $json['message'] . "\n"; // some other error message I haven't anticipated.  perhaps it's important?
        }
    }
}

function send_coins($amount, $address) {
    global $bitcoin;

    if (DEBUG) printf("sending " . BTC_FORMAT . " to %s\n", $amount, $address);

    unlock_wallet();

    while (true) {
        try {
            $bitcoin->sendtoaddress($address, $amount);
            break;
        } catch (Exception $e) {
            print $e->getMessage() . " (retrying in 10 seconds...)\n";
            sleep(10);
        }
    }
}

function send_bet($amount) {
    set_fee(FEE_WHILE_PLAYING);
    send_coins($amount, BET_ADDRESS);
    set_fee(FEE_AFTER_PLAYING);
}

function stash_coin($amount) {
    set_fee(FEE_WHILE_STASHING);
    send_coins($amount, STASH_ADDRESS);
    set_fee(FEE_AFTER_PLAYING);
}

function play($balance) {
    $total_stashed = $pending_stash = 0;

    $bet_count = $win_count = $lose_count = 0;
    $total_fee = 0;

    while (true) {
        // treat winnings which we intend to stash but didn't yet as
        // if they're not in the wallet for min and max bet calculation purposes
        if (FIXED_MIN_BET)
            $min_bet = FIXED_MIN_BET;
        else
            $min_bet = ($balance - $pending_stash) * MIN_BET_AS_BALANCE_PERCENTAGE / 100.0;

        if (FIXED_MAX_BET)
            $max_bet = FIXED_MAX_BET;
        else
            $max_bet = ($balance - $pending_stash) * MAX_BET_AS_BALANCE_PERCENTAGE / 100.0;

        if ($min_bet < SD_MIN_BET)
            return array($total_stashed, $pending_stash,
                         sprintf("balance " . BTC_FORMAT . " gives min_bet " . BTC_FORMAT . " which is less than SD's min bet of " . BTC_FORMAT,
                                 $balance - $pending_stash, $min_bet, SD_MIN_BET));

        printf("\nstarting new round; balance " . BTC_FORMAT . "; min: " . BTC_FORMAT . "; max: " . BTC_FORMAT . "\n",
               $balance - $pending_stash, $min_bet, $max_bet);

        $starting_balance = $balance - $pending_stash;
        $bet = $min_bet;

        while ($bet <= $max_bet) {
            if (DEBUG) {
                print "balance: " . $balance . "\n";
                print "pending_stash: " . $pending_stash . "\n";
                print "difference: " . ($balance - $pending_stash) . "\n";
            }
            // check we can afford this bet, else give up
            if ($bet > $balance - $pending_stash)
                return array($total_stashed, $pending_stash,
                             sprintf("can't afford bet of " . BTC_FORMAT . " with balance " . BTC_FORMAT, $bet, $balance - $pending_stash));

            // wait for confirms if necessary
            if (WAIT_FOR_CONFIRMS && ($confirmed_balance = get_confirmed_balance()) < $bet) {
                $unconfirmed_balance = $balance - $confirmed_balance;
                printf("      [ wait for confirms; bet = " . BTC_FORMAT . "; confirmed = " . BTC_FORMAT . "; unconfirmed = " . BTC_FORMAT . " ",
                       $bet, $confirmed_balance, $unconfirmed_balance);
                $old_confirmed_balance = $confirmed_balance;
                $count = 0;
                while (WAIT_FOR_CONFIRMS && ($confirmed_balance = get_confirmed_balance()) < $bet) {
                    if ($old_confirmed_balance != $confirmed_balance)
                        printf(" ]\n[           still waiting; bet = " . BTC_FORMAT . "; confirmed = " . BTC_FORMAT . "; unconfirmed = " . BTC_FORMAT . " ",
                               $bet, $confirmed_balance, $unconfirmed_balance);
                    sleep(3);
                    if ($count++ % 10 == 0)
                        print ".";
                    $old_confirmed_balance = $confirmed_balance;
                }
                print " ]\n";
            } else if (DEBUG) {
                print "no need to wait for confirmations\n";
                printf("confirmed_balance = " . BTC_FORMAT . "\n", get_confirmed_balance());
                printf("         this bet = " . BTC_FORMAT . "\n", $bet);
            }

            // show stats so far
            if ($lose_count)
                $ratio = sprintf("%7.5f", $win_count / $lose_count);
            else
                $ratio = ' (inf) ';
            printf("%3d : Won %2d; Lost %2d; W:L ratio %s   bet " . BTC_FORMAT . " ", $bet_count+1, $win_count, $lose_count, $ratio, $bet);

            if (DRY_RUN) return array($total_stashed, $pending_stash, "dry run");

            send_bet($bet);
            $bet_count++;

            $balance_after_betting = get_balance();
            $fee = $balance - $bet - $balance_after_betting;
            $total_fee += $fee;
            if (DEBUG) {
                printf("balance_after_betting = " . BTC_FORMAT . "\n", $balance_after_betting);
                printf("fee = " . BTC_FORMAT . " - " . BTC_FORMAT . " - " . BTC_FORMAT . " = " . BTC_FORMAT . "\n", $balance, $bet, $balance_after_betting, $fee);
                printf("total fee = " . BTC_FORMAT . "\n", $total_fee);
            }

            // wait for the balance to change, indicating the SD payment arrived
            $count = 0;
            while (($balance = get_balance()) == $balance_after_betting) {
                sleep(3);
                if ($count++ % 10 == 0)
                    print ".";
            }
            print " ";

            if (DEBUG) printf("new balance = " . BTC_FORMAT . "\n", $balance);

            // if the payout is more than the bet then we won
            $payout = $balance - $balance_after_betting;
            $win = $payout > $bet;
            printf("payout " . BTC_FORMAT . " : %s\n", $payout, $win ? "WIN" : "LOSE");

            if ($win) {
                $win_count++;
                $net_win = $balance - $pending_stash - $starting_balance;
                if (DEBUG)
                    print "net_win = balance $balance - pending $pending_stash - starting $starting_balance = $net_win\n";
                $stash_amount = $net_win * STASH_PERCENTAGE / 100.0;
                $pending_stash += $stash_amount;
                $total_stashed += $stash_amount;
                if (DEBUG) {
                    print "pending_stash: " . $pending_stash . "\n";
                    print "total_stashed: " . $total_stashed . "\n";
                }
                printf("      [ net win: " . BTC_FORMAT . "; stashing %d%% = " . BTC_FORMAT . "; total stashed = " . BTC_FORMAT . " ]\n", $net_win, STASH_PERCENTAGE, $stash_amount, $total_stashed);

                // check whether we have enough winnings to justify
                // sending to the stash address yet
                if ($pending_stash >= STASH_THRESHOLD) {
                    if (DEBUG) {
                        print "stashing pending $pending_stash\n";
                        print "before: balance: " . get_balance() . " or $balance\n";
                    }
                    stash_coin($pending_stash);
                    $pending_stash = 0;
                    $balance = get_balance();
                    if (DEBUG)
                        print "after: balance: " . get_balance() . "\n";
                }

                // three positive reasons for stopping play:
                // 1. we stashed enough winnings
                if ($total_stashed >= TARGET_WINNINGS)
                    return array($total_stashed, $pending_stash,
                                 sprintf("reached stash target " . BTC_FORMAT, TARGET_WINNINGS));

                // 2. we won enough times
                if ($win_count == TARGET_WINS)
                    return array($total_stashed, $pending_stash,
                                 sprintf("reached win target %d", TARGET_WINS));

                // 3. win:loss ratio is high enough (with enough total bets)
                if ($bet_count >= MIN_BETS_FOR_RATIO_RULE and $win_count / $lose_count >= TARGET_WINS_TO_LOSSES_RATIO)
                    return array($total_stashed, $pending_stash,
                                 sprintf("reached win:loss ratio target %f with %d (at least %d) bets",
                                         TARGET_WINS_TO_LOSSES_RATIO, $bet_count, MIN_BETS_FOR_RATIO_RULE));

                // 4. STOP_ON_WIN file exists - it's a manual way of quitting on the next win
                if (file_exists(STOP_ON_WIN_IF_EXISTS))
                    return array($total_stashed, $pending_stash,
                                 "we won, and file '" . STOP_ON_WIN_IF_EXISTS . "' exists");

                // break to the outer loop, to recalculate min and max bet sizes
                break;
            }

            $lose_count++;
            $bet *= BET_MULTIPLIER;
        }

        if ($bet > $max_bet)
            print "reached max bet; starting over\n";
    }
}

function main() {
    global $wallet_passphrase;

    check_bitcoin_connection();

    if (WALLET_PASSPHRASE) {
        if (WALLET_PASSPHRASE == 'prompt')
            $wallet_passphrase = prompt_silent("Enter Wallet Passphrase:");
        else
            $wallet_passphrase = WALLET_PASSPHRASE;
    } else
        $wallet_passphrase = false;

    $start_balance = get_balance();
    list ($stashed, $pending_stash, $status) = play($start_balance);
    $final_balance = get_balance();

    printf("\n\nstopped playing because: '%s'\n\n", $status);
    printf(" starting balance: " . BTC_FORMAT . "\n", $start_balance);
    printf("   stashed amount: " . BTC_FORMAT . " + " . BTC_FORMAT . " pending\n", $stashed - $pending_stash, $pending_stash);
    printf("   ending balance: " . BTC_FORMAT . "\n", $final_balance);
    printf("           profit: " . BTC_FORMAT . "\n", $final_balance + $stashed - $pending_stash - $start_balance);
}

main();

?>
