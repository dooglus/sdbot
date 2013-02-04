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

// what percentage of our winnings to send to the stash address (this
// is the net winnings, right?  so if we lose 1, lose 2, win 4 then we
// stash some percentage of 1 coin, not of the 4 coins we won in the end?)
// use 0 to never stash winnings away.
define('STASH_PERCENTAGE',              90); // %

// save up stashed amounts in the wallet until they reach this threshold (saves network fees)
// set to 0 if you want to actually transfer stashed coins after every win, no matter how small
define('STASH_THRESHOLD',               0.025); 

// what percentage of our balance do we bet as the first bet
define('MIN_BET_AS_BALANCE_PERCENTAGE', 1); // %

// what percentage of our balance are we willing to bet in one go
define('MAX_BET_AS_BALANCE_PERCENTAGE', 50); // %

// stop once we've sent this much to the stash address
define('TARGET_WINNINGS',               0.05);

// stop once we've won this many times
define('TARGET_WINS',                   30); 

// stop once ratio wins/losses is greater than this BUT...
define('TARGET_WINS_TO_LOSSES_RATIO',   1.9); 

// ... only if we have placed at least this many bets
define('MIN_BETS_FOR_RATIO_RULE',       10);

// satoshidice bet address
define('BET_ADDRESS',                   '1dice8EMZmqKvrGE4Qc9bUFf9PX3xaYDp');

// satoshidice minimum bet amount
define('SD_MIN_BET',                    0.01);

// how much to multiply bet by when we lose
define('BET_MULTIPLIER',                2);

// should we wait until we have enough confirmed funds?
define('WAIT_FOR_CONFIRMS',             true);

// set min fee while playing
define('FEE_WHILE_PLAYING',             0.001);

// set min fee back after playing
define('FEE_AFTER_PLAYING',             0);

// width and precision of BTC formatting
// %11.8f means 11 characters wide, with 8 decimal digits (12.12345678)
define('BTC_FORMAT',                    '%8.4f BTC');

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

function send_coins($amount, $address) {
    global $bitcoin;
    if (DEBUG) printf("sending " . BTC_FORMAT . " to %s\n", $amount, $address);
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
    send_coins($amount, BET_ADDRESS);
}

function stash_coin($amount) {
    send_coins($amount, STASH_ADDRESS);
}

function play($balance) {
    $total_stashed = $pending_stash = 0;

    $bet_count = $win_count = $lose_count = 0;
    $total_fee = 0;

    while (true) {
        // treat winnings which we intend to stash but didn't yet as
        // if they're not in the wallet for min and max bet calculation purposes
        $min_bet = ($balance - $pending_stash) * MIN_BET_AS_BALANCE_PERCENTAGE / 100.0;
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
            // check we can afford this bet, else give up
            if ($bet > $balance - $pending_stash)
                return array($total_stashed, $pending_stash,
                             sprintf("can't afford bet of " . BTC_FORMAT . " with balance " . BTC_FORMAT, $bet, $balance - $pending_stash));

            // wait for confirms if necessary
            if (WAIT_FOR_CONFIRMS && ($confirmed_balance = get_confirmed_balance()) < $bet) {
                $unconfirmed_balance = $balance - $confirmed_balance;
                printf("[ wait for confirms; bet = " . BTC_FORMAT . "; confirmed = " . BTC_FORMAT . "; unconfirmed = " . BTC_FORMAT . " ",
                       $bet, $confirmed_balance, $unconfirmed_balance);
                while (WAIT_FOR_CONFIRMS && ($confirmed_balance = get_confirmed_balance()) < $bet) {
                    print ".";
                    sleep(30);
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
            printf("%2d : Won %2d; Lost %2d; W:L ratio %s   bet " . BTC_FORMAT . " ", $bet_count+1, $win_count, $lose_count, $ratio, $bet);

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
            while (($balance = get_balance()) == $balance_after_betting) {
                sleep(10);
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
                $stash_amount = $net_win * STASH_PERCENTAGE / 100.0;
                $pending_stash += $stash_amount;
                $total_stashed += $stash_amount;
                printf("[ net win: " . BTC_FORMAT . "; stashing %d%% = " . BTC_FORMAT . "; total stashed = " . BTC_FORMAT . " ]\n", $net_win, STASH_PERCENTAGE, $stash_amount, $total_stashed);

                // check whether we have enough winnings to justify
                // sending to the stash address yet
                if ($pending_stash >= STASH_THRESHOLD) {
                    stash_coin($pending_stash);
                    $pending_stash = 0;
                    $balance = get_balance();
                }

                // three positive reasons for stopping play:
                // 1. we stashed enough winnings
                if ($stash_amount >= TARGET_WINNINGS)
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
    check_bitcoin_connection();
    set_fee(FEE_WHILE_PLAYING);
    $start_balance = get_balance();
    list ($stashed, $pending_stash, $status) = play($start_balance);
    $final_balance = get_balance();
    set_fee(FEE_AFTER_PLAYING);

    printf("\n stopped playing because: '%s'\n\n", $status);
    printf(" starting balance: " . BTC_FORMAT . "\n", $start_balance);
    printf("   stashed amount: " . BTC_FORMAT . " + " . BTC_FORMAT . " pending\n", $stashed - $pending_stash, $pending_stash);
    printf("   ending balance: " . BTC_FORMAT . "\n", $final_balance);
    printf("           profit: " . BTC_FORMAT . "\n", $final_balance + $stashed - $pending_stash - $start_balance);
}

main();

?>
