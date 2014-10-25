<html>
  <head>
    <title>Network Check</title>
  </head>
  <body>
<?php 

  // From:
  // http://www.thecave.info/php-ping-script-to-check-remote-server-or-website/
  function ping($host,$port=80,$timeout=6)
  {
    $fsock = fsockopen($host, $port, $errno, $errstr, $timeout);
    if ( ! $fsock )
    {
      return FALSE;
    }
    else
    {
      fclose($fsock);
      return TRUE;
    }
  }

  function my_log($message) {
    // Function to write a line to the program log
    // Example: 2014-10-24 21:14:01: Google (google.com) DOWN => UP
    global $log_file;
    error_log(PHP_EOL . date("Y-m-d G:i:s") . ": " . $message, 3, $log_file);
  }

  // Change to the directory of my script -- this makes sure my imports
  // and json files are accessed correctly.
  chdir(dirname(__FILE__));

  // Time in the format similar to Wed 8:24 PM 
  $timestamp = date("D g:i A");

  // Read in the app config and servers to check
  $appConfig = json_decode(file_get_contents("app_config.json"));

  // Restore the last saved state of the VPN servers
  $last_net_state = json_decode(file_get_contents($appConfig->app->state_filename), true);
  $net_state_existed = !is_null($last_net_state);
  $send_email = false;
  $email_body = $appConfig->smtp->Preamble;
  $log_file = $appConfig->app->log_filename;
  // Original twitter code from:
  // https://github.com/vickythegme/cron-job-twitter/blob/master/cron.php
  // Adapted by Vic Levy in October 2014

  // Include the abraham's twitteroauth library
  // https://github.com/abraham/twitteroauth
  require_once('twitteroauth.php');

  // Create a connection using my app's settings from dev.twitter.com
  $connection = new TwitterOAuth($appConfig->tweet->consumerKey,
                                 $appConfig->tweet->consumerSecret,
                                 $appConfig->tweet->accessToken,
                                 $appConfig->tweet->accessSecret);

  // Check each control server to see if it is up. If the control servers are "down,"
  // it's an indication the problem is on our end and not on the servers being 
  // monitored
  $any_control_server_up = false;
  foreach ($appConfig->control_servers as &$server) {
    // Check the server
    $any_control_server_up = ping($server->host);
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
      $server_up = ping($server->host);

      // Construct a user message from the state
      // Example: "Google is UP Fri 08:41 PM."
      $status = $server_up ? "UP" : "DOWN";
      $message = $server->name . " is " . $status . " " . $timestamp . ".";
      echo '<br />' . $message;

      // Did the state of this server change?
      if ($status != $last_net_state[$server->name]) {
        // Status for this server changed
        // Example: Google (google.com) DOWN => UP
        my_log($server->name . " (" . $server->host . ") " 
             . $last_net_state[$server->name] . " => " . $status);
        $any_server_change = TRUE;
        $last_net_state[$server->name] = $status;

        // We tweet only if there was a prior state file
        if ($net_state_existed) {

          // Tweet new server status
          // Example: "Google is UP. Latest status: http://www.mywebserver.com/tweetnetstat"
          $tweet = $message . " " . $appConfig->app->tweet_suffix;
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
          // Tweet was omitted because the state file could not be read
          // Example: Google is UP. Tweet was not sent because the prior state was not present.
          $tweet_result_text = $message . ' Tweet was not sent because the prior state was not present.';
        }

        // Log what happened
        my_log($tweet_result_text);
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

  if ($any_server_change) {
    // The state of a server has changed, so send an email.
    // We no not omit the email if we had no prior state
    $send_email = true;
  }

  if ($send_email) {
    // Send an email
    // https://github.com/PHPMailer/PHPMailer

    echo '<br />Email contents:';
    echo '<br /><br />' . $email_body;

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
      my_log("Mailer Error: " . $mail->ErrorInfo);
    } 
    else {
      echo '<br />Message has been emailed.';
      my_log("Message has been emailed.");
    }

  }
  else {
    echo '<br />No change. Email was not sent.';
  }

?> 
 </body>
</html>