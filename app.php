<?php
/*
 * Simple SMS gateway using Amazon AWS
 * @author: Alouit Alexandre <alexandre.alouit@gmail.com>
 */

require 'vendor/autoload.php';

use Aws\Sns\SnsClient;

$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);

/*
* compute signature
* @params: (array) data
* @return: (string) signature
*/
function signature($data, $config)
{
    if (!is_array($data))
        return false;

    ksort($data);

    $signature = array($config['endpoint']);

    foreach ($data as $key => $value) {
        $signature[] = sprintf('%s=%s', $key, $value);
    }

    unset($data);

    $signature[] = $config['token'];

    $signature = implode(",", $signature);

    return base64_encode(sha1($signature, true));
}

/*
* do request
* @params: (array) data
* @return: (string) response / (bool) state
*/
function request($data, $config)
{
    $data['phone_number'] = $config['phonenumber'];
    $signature = signature($data, $config);
    $data = http_build_query($data);

    $context = array(
        'http' => array(
            'method' => 'POST',
            'ignore_errors' => true,
            'timeout' => 5,
            'header' => array(
                "Content-Type: application/x-www-form-urlencoded",
                "User-Agent: " . $config['user-agent'],
                "x-request-signature: " . $signature,
                "Content-Length: " . strlen($data)
            ),
            'content' => $data
        )
    );

    return @file_get_contents($config['endpoint'], false, stream_context_create($context));
}

$result = request(
    array(
        'action' => 'outgoing'
    ),
    $config
);

if (!$result = json_decode($result, true))
    return false;

foreach ($result as $events) {
    foreach ($events as $event) {
        switch (@$event['event']) {
                // send a message
            case 'send':

                foreach ($event['messages'] as $message) {
                    // send queue request
                    request(
                        array(
                            'action' => 'send_status',
                            'status' => 'queued',
                            'id' => $message['id']
                        ),
                        $config
                    );

                    $SnSclient = new SnsClient(
                        array(
                            'region' => 'eu-west-3',
                            'version' => '2010-03-31',
                            'credentials' => array(
                                'key'    => $config['aws-access-key'],
                                'secret' => $config['aws-secret-key']
                            )
                        )
                    );

                    $result = $SnSclient->publish(
                        array(
                            'Message' => $message['message'],
                            'PhoneNumber' => $message['to'],
                        )
                    );

                    // set as send
                    request(
                        array(
                            'action' => 'send_status',
                            'status' => 'sent',
                            'id' => $message['id']
                        ),
                        $config
                    );
                }

                break;
        }
    }
}
