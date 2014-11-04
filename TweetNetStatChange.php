<html>
  <head>
    <title>Network Check</title>
  </head>
  <body>
<?php 

  // Adapted from:
  // http://www.thecave.info/php-ping-script-to-check-remote-server-or-website/
  function ping($host, $timeout, $retry_count, $port=80)
  {
    for ($i = 0; $i < $retry_count; $i++) 
    {
      $fsock = fsockopen($host, $port, $errno, $errstr, $timeout);
      if ($fsock ) 
      {
          fclose($fsock);
          return TRUE;
      }
    }
    return FALSE;
  }

  // Return the most recent network status changes, from the log
  function get_latest_changes()
  {
    $log_lines = `tail -16 ./net_changes.txt`;
    $lines = preg_split ('/$\R?^/m', $log_lines);

    $text = "";
    $text .= "<br />Latest network changes:";
    $text .=  "<pre>";

    foreach($lines as $line) {
      $text .= "<br />" . $line;
    }
    $text .= "<br /></pre>";

    return $text;
  }

  function my_log($message, $is_state_change) {
    // Function to write a line to the program log
    // Example: 2014-10-24 21:14:01: Google (google.com) UP
    global $log_file, $state_change_file;
    error_log(PHP_EOL . date("Y-m-d H:i:s") . ": " . $message, 3, $log_file);
    if ($is_state_change) {
      error_log(PHP_EOL . date("Y-m-d H:i:s") . ": " . $message, 3, $state_change_file);
    }
  }


  // Change to the directory of my script -- this makes sure my imports
  // and json files are accessed correctly.
  chdir(dirname(__FILE__));

  // Time in the format similar to Wed 8:24 PM 
  $timestamp = date("D g:i A");

  // Read in the app config and servers to check
  $appConfig   = json_decode(file_get_contents("app_config.json"));
  $timeout     = $appConfig->app->socket_timeout;
  $retry_count = $appConfig->app->socket_retry_count;

  // Restore the last saved state of the VPN servers
  $last_net_state = json_decode(file_get_contents($appConfig->app->state_filename), true);
  $net_state_existed = !is_null($last_net_state);
  $send_email = false;
  $email_body = $appConfig->smtp->Preamble;
  $log_file = $appConfig->app->log_filename;
  $state_change_file = $appConfig->app->state_change_log_filename;
  // Original twitter code from:
  // https://github.com/vickythegme/cron-job-twitter/blob/master/cron.php
  // Adapted by Vic Levy in October 2014

  // Include the abraham's twitteroauth library
  // https://github.com/abraham/twitteroauth
  require_once('twitteroauth.php');

  // Create a connection using my app's settings from dev.twitter.com
  $connection = NULL;
  if ($appConfig->notification->tweet) {
    $connection = new TwitterOAuth($appConfig->tweet->consumerKey,
                                   $appConfig->tweet->consumerSecret,
                                   $appConfig->tweet->accessToken,
                                   $appConfig->tweet->accessSecret);
  }

  // Check each control server to see if it is up. If the control servers are "down,"
  // it's an indication the problem is on our end and not on the servers being 
  // monitored
  echo "Timeout: ".strval($appConfig->app->socket_timeout)."s, retries: ".strval($appConfig->app->socket_retry_count);

  $any_control_server_up = false;
  foreach ($appConfig->control_servers as &$server) {
    // Check the server
    $any_control_server_up = ping($server->host, $timeout, $retry_count);
    if ($any_control_server_up) {
      echo "<br />Server " . $server->name . " (" . $server->host . ") was pinged.";
      break;
    }
    else {
      echo "<br />Server " . $server->name . " (" . $server->host . ") could not be pinged.";
    }
  }

  if ($any_control_server_up) {

    // Check each monitored server to see if its up/down state has changed
    $any_server_change = FALSE;
    foreach ($appConfig->monitored_servers as &$server) {

      // Check the server
      $server_up = ping($server->host, $timeout, $retry_count);

      // Construct a user message from the state
      // Example: "Google is UP Fri 08:41 PM."
      $status = $server_up ? "UP" : "DOWN";
      $message = $server->name . " is " . $status . " " . $timestamp . ".";
      echo '<br />' . $message;

      // Did the state of this server change?
      if ($status != $last_net_state[$server->name]) {
        // Status for this server changed
        // Example: Google (google.com) UP
        my_log($server->name . " (" . $server->host . ") " . $status, TRUE);
        $any_server_change = TRUE;
        $last_net_state[$server->name] = $status;

        // We tweet only if there was a prior state file
        if ($net_state_existed) {

          // Tweet new server status
          // Example: "Google is UP. Latest status: http://www.mywebserver.com/tweetnetstat"
          $tweet = $message . " " . $appConfig->app->tweet_suffix;

          if ($appConfig->notification->tweet) {
            # Tweet the message
            $result = $connection->post('statuses/update', array('status' => $tweet));
            if ($result and $result->id) {
              // Tweet was posted successfully, and $result contains the tweet data
              // Example: "Google is UP. Latest status: http://www.mywebserver.com/tweetnetstat" Tweeted by @mytwitter
              $tweet_result_text = '"' . $result->text . '" Tweeted by @' . $result->user->screen_name;
            }
            else {
              // Tweet failed
              // Example: "Google is UP. Status page: http://www.mywebserver.com/tweetnetstat" Tweet failed. Reason: "Failed to authenticate (code 216)."
              $tweet_result_text = '"' . $tweet . '" Tweet failed. Reason: "' . 
                                   $result->errors[0]->message . 
                                   '" (code '. strval($result->errors[0]->code) . ').';
            }
          }
          else {
            // Tweet was disabled
            $tweet_result_text = $message . ' Tweeting disabled in the config.';
          }
        }
        else {
          // Tweet was omitted because the state file could not be read
          // Example: Google is UP. Tweet was not sent because the prior state was not present.
          $tweet_result_text = $message . ' Tweet was not sent because the prior state was not present.';
        }

        // Log what happened
        my_log($tweet_result_text, FALSE);
      }
      else {
        // There was no change to this server
        // Example: Google is UP.
        $tweet_result_text = $message;
      }

      // Update email body
      $email_body .= '<br /><br />' . $tweet_result_text;
    }
    // Save the new server state; the only reason we do this even if no change, is 
    // to make it easy to check the web server to see when the last check took place.
    // If we don't care about that, we can put it under the $any_server_change conditional block.
    if (true) {
      $result = file_put_contents($appConfig->app->state_filename, json_encode($last_net_state));
      if (!$net_state_existed) {
        $email_body .= '<br /><br />State initialized.';
      }
    }
  }
  else {
    // No control servers were up. We don't tweet but we do want send an email that 
    // we had an unsuccessful check.
    $email_body .= '<br />Control servers could not be contacted.';
    $send_email = true;
  }

  // Emailing can be turned off in the configuration
  if ($any_server_change) {
    // The state of a server has changed, so send an email.
    $send_email = true;
  }

  // Emailing can be turned off in the configuration
  if ($send_email and !$appConfig->notification->email) {
    echo "<br />Emailing is disabled.";
    $send_email = false;
  }

  // Append the latest changes to the email
  $changes = get_latest_changes();
  $email_body .= $changes;
  $email_body .= "<br />" . $appConfig->app->tweet_suffix;
  $email_body .= "<br />" . $appConfig->app->follow_plug;

  if ($send_email) {
    // Send an email
    // https://github.com/PHPMailer/PHPMailer

    require_once('./php_mailer/PHPMailerAutoload.php');
    $mail = new PHPMailer();
    $mail->IsSMTP();
    $mail->CharSet    = $appConfig->smtp->CharSet;
    $mail->Host       = $appConfig->smtp->Host;
    $mail->SMTPDebug  = $appConfig->smtp->SMTPDebug;
    $mail->SMTPAuth   = $appConfig->smtp->SMTPAuth;
    $mail->Port       = $appConfig->smtp->Port;
    $mail->Username   = $appConfig->smtp->Username;
    $mail->Password   = $appConfig->smtp->Password;
    $mail->SMTPSecure = $appConfig->smtp->SMTPSecure;
    $mail->Port       = $appConfig->smtp->Port;
    $mail->From       = $appConfig->smtp->From->email;
    $mail->FromName   = $appConfig->smtp->From->name;
    $mail->addReplyTo($appConfig->smtp->ReplyTo->email,
                      $appConfig->smtp->ReplyTo->name);
    $mail->WordWrap = $appConfig->smtp->From->WordWrap;
    $mail->isHTML($appConfig->smtp->From->isHTML);

    foreach ($appConfig->smtp->To as &$contact) {
      $mail->addAddress($contact->email, $contact->name);
    }
    foreach ($appConfig->smtp->CC as &$contact) {
      $mail->addCC($contact->email, $contact->name);
    }
    foreach ($appConfig->smtp->BCC as &$contact) {
      $mail->addBCC($contact->email, $contact->name);
    }

    $mail->Subject = $appConfig->smtp->Subject;
    $mail->Body    = $email_body;
    $mail->AltBody = $mail->Body;

    if(!$mail->send()) {
      echo '<br />Message could not be emailed.';
      echo '<br />Mailer Error: ' . $mail->ErrorInfo;
      my_log("Mailer Error: " . $mail->ErrorInfo, FALSE);
    } 
    else {
      echo '<br />Message has been emailed.';
      my_log("Message has been emailed.", FALSE);
    }

  }
  else {
    echo '<br />Email was not sent.';
  }

  // Echo the latest changes to the browser
  echo $changes;
  echo $appConfig->app->follow_plug;
?> 
 </body>
</html>