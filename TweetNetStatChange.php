<html>
  <head>
    <title>PCI VPN Check</title>
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
      return TRUE;
    }
  }

  // Change to the directory of my script -- this makes sure my imports
  // and json files are accessed correctly.
  chdir(dirname(__FILE__));

  // Read in the app config and servers to check
  $appConfig = json_decode(file_get_contents("app_config.json"));

  // Restore the last saved state of the VPN servers
  $last_net_state = json_decode(file_get_contents($appConfig->app->state_filename), true);
  $net_state_was_null = is_null($last_net_state);

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

  // Check each server to see if its up/down state has changed
  $any_server_change = FALSE;
  $email_body = $appConfig->smtp->Preamble;
  foreach ($appConfig->servers as &$server) {

    // Check the server
    $server_up = ping($server->host);

    // Construct a user message from the state
    if ($server_up) {
      $status = "UP";
    }
    else {
      $status = "DOWN";
    }
    $message = $server->name . " is " . $status . ".";
    echo '<br />' . $message;

    // Did the state of this server change?
    if ($status != $last_net_state[$server->name]) {
      // Status for this server changed
      $any_server_change = TRUE;
      $last_net_state[$server->name] = $status;

      // We omit the tweet if there was no prior state file
      if (!$net_state_was_null) {

        // Tweet new server status      
        $result = $connection->post('statuses/update', array('status' => $message ));
        if ($result and $result->id) {
          // Tweet was posted successfully, and $result contains the tweet data
          $tweet_result_text = '"' . $result->text . '" Tweeted by @' . $result->user->screen_name;
        } 
        else {
          // Tweet failed
          $tweet_result_text = '"' . $message . '" Tweet failed. Reason: "' . 
                               $result->errors[0]->message . 
                               '" (code '. strval($result->errors[0]->code) . ').';
        }
      }
      else {
        // Tweet was omitted because the state file could not be read
        $tweet_result_text = $message . ' Tweet was not sent because the prior state was not present.';
      }
    }
    else {
        $tweet_result_text = $message;
    }

    // Update email body
    $email_body .= '<br />' . $tweet_result_text;
  }

  // Save the new server state; the only reason we do this even if no change, is 
  // to make it easy to check the web server to see when the last check took place.
  // If we don't care about that, we can put it under the $any_server_change conditional block.
  if (true) {
    $result = file_put_contents($appConfig->app->state_filename, json_encode($last_net_state));
    if ($net_state_was_null) {
      $email_body .= '<br />State initialized.';
    }
  }

  if ($any_server_change) {
    // The state of a server has changed, so send an email.
    // We no not omit the email if we had no prior state
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
    } 
    else {
      echo '<br />Message has been emailed.';
    }

  }
  else {
    echo '<br />No change. Email was not sent.';
  }

?> 
 </body>
</html>