<?php

namespace Office365Mail\Transport;

use Illuminate\Mail\Transport\Transport;
use Swift_Mime_SimpleMessage;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model\UploadSession;

class Office365MailTransport extends Transport
{

    public function __construct()
    {
        parent::__construct();
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {

        $this->beforeSendPerformed($message);

        $graph = new Graph();

        $graph->setAccessToken($this->getAccessToken());

        // Special treatment if the message has too large attachments
        $messageBody = $this->getBody($message, true);
        $messageBodySizeMb = json_encode($messageBody);
        $messageBodySizeMb = strlen($messageBodySizeMb);
        $messageBodySizeMb = $messageBodySizeMb / 1048576; //byte -> mb

        if ($messageBodySizeMb >= 4) {
            unset($messageBody);
            $graphMessage = $graph->createRequest("POST", "/users/" . key($message->getFrom()) . "/messages")
                ->attachBody($this->getBody($message))
                ->setReturnType(\Microsoft\Graph\Model\Message::class)
                ->execute();

            foreach ($message->getChildren() as $attachment) {
                if ($attachment instanceof \Swift_Mime_SimpleMimeEntity) {
                    $fileName = $attachment->getHeaders()->get('Content-Disposition')->getParameter('filename');
                    $content = $attachment->getBody();
                    $fileSize = strlen($content);
                    $size = $fileSize / 1048576; //byte -> mb
                    $id = $attachment->getId();
                    $attachmentMessage = [
                        'AttachmentItem' => [
                            'attachmentType' => 'file',
                            'name' => $fileName,
                            'size' => strlen($content)
                        ]
                    ];

                    if ($size <= 3) { //ErrorAttachmentSizeShouldNotBeLessThanMinimumSize if attachment <= 3mb, then we need to add this
                        $attachmentBody = [
                            "@odata.type" => "#microsoft.graph.fileAttachment",
                            "name" => $attachment->getHeaders()->get('Content-Disposition')->getParameter('filename'),
                            "contentType" => $attachment->getBodyContentType(),
                            "contentBytes" => base64_encode($attachment->getBody()),
                            'contentId'    => $id
                        ];

                        $addAttachment = $graph->createRequest("POST", "/users/" . key($message->getFrom()) . "/messages/" . $graphMessage->getId() . "/attachments")
                            ->attachBody($attachmentBody)
                            ->setReturnType(UploadSession::class)
                            ->execute();
                    } else {
                        //upload the files in chunks of 4mb....
                        $uploadSession = $graph->createRequest("POST", "/users/" . key($message->getFrom()) . "/messages/" . $graphMessage->getId() . "/attachments/createUploadSession")
                            ->attachBody($attachmentMessage)
                            ->setReturnType(UploadSession::class)
                            ->execute();

                        $fragSize =  1024 * 1024 * 4; //4mb at once...
                        $numFragments = ceil($fileSize / $fragSize);
                        $contentChunked = str_split($content, $fragSize);
                        $bytesRemaining = $fileSize;

                        $i = 0;
                        while ($i < $numFragments) {
                            $chunkSize = $numBytes = $fragSize;
                            $start = $i * $fragSize;
                            $end = $i * $fragSize + $chunkSize - 1;
                            if ($bytesRemaining < $chunkSize) {
                                $chunkSize = $numBytes = $bytesRemaining;
                                $end = $fileSize - 1;
                            }
                            $data = $contentChunked[$i];
                            $content_range = "bytes " . $start . "-" . $end . "/" . $fileSize;
                            $headers = [
                                "Content-Length" => $numBytes,
                                "Content-Range" => $content_range
                            ];
                            $client = new \GuzzleHttp\Client();
                            $tmp = $client->put($uploadSession->getUploadUrl(), [
                                'headers'         => $headers,
                                'body'            => $data,
                                'allow_redirects' => false,
                                'timeout'         => 1000
                            ]);
                            $result = $tmp->getBody() . '';
                            $result = json_decode($result); //if body == empty, then the file was successfully uploaded
                            $bytesRemaining = $bytesRemaining - $chunkSize;
                            $i++;
                        }
                    }
                }
            }

            //definetly send the message
            $graph->createRequest("POST", "/users/" . key($message->getFrom()) . "/messages/" . $graphMessage->getId() . "/send")->execute();
        } else {
            $graphMessage = $graph->createRequest("POST", "/users/" . key($message->getFrom()) . "/sendmail")
                ->attachBody($messageBody)
                ->setReturnType(\Microsoft\Graph\Model\Message::class)
                ->execute();
        }

        $this->sendPerformed($message);

        return $this->numberOfRecipients($message);
    }

    /**
     * Get body for the message.
     *
     * @param \Swift_Mime_SimpleMessage $message
     * @param bool $withAttachments
     * @return array
     */

    protected function getBody(Swift_Mime_SimpleMessage $message, $withAttachments = false)
    {
        $messageData = [
            'from' => [
                'emailAddress' => [
                    'address' => key($message->getFrom()),
                    'name' => current($message->getFrom())
                ]
            ],
            'toRecipients' => $this->getTo($message),
            'ccRecipients' => $this->getCc($message),
            'bccRecipients' => $this->getBcc($message),
            'replyTo' => $this->getReplyTo($message),
            'subject' => $message->getSubject(),
            'body' => [
                'contentType' => $message->getBodyContentType() == "text/html" ? 'html' : 'text',
                'content' => $message->getBody()
            ]
        ];

        if ($withAttachments) {
            $messageData = ['message' => $messageData];
            //add attachments if any
            $attachments = [];
            foreach ($message->getChildren() as $attachment) {
                if ($attachment instanceof \Swift_Mime_SimpleMimeEntity) {
                    $attachments[] = [
                        "@odata.type" => "#microsoft.graph.fileAttachment",
                        "name" => $attachment->getHeaders()->get('Content-Disposition')->getParameter('filename'),
                        "contentType" => $attachment->getBodyContentType(),
                        "contentBytes" => base64_encode($attachment->getBody()),
                        'contentId'    => $attachment->getId()
                    ];
                }
            }
            if (count($attachments) > 0) {
                $messageData['message']['attachments'] = $attachments;
            }
        }

        return $messageData;
    }

    /**
     * Get the "to" payload field for the API request.
     *
     * @param \Swift_Mime_SimpleMessage $message
     * @return string
     */
    protected function getTo(Swift_Mime_SimpleMessage $message)
    {
        return collect((array) $message->getTo())->map(function ($display, $address) {
            return $display ? [
                'emailAddress' => [
                    'address' => $address,
                    'name' => $display
                ]
            ] : [
                'emailAddress' => [
                    'address' => $address
                ]
            ];
        })->values()->toArray();
    }

    /**
     * Get the "Cc" payload field for the API request.
     *
     * @param \Swift_Mime_SimpleMessage $message
     * @return string
     */
    protected function getCc(Swift_Mime_SimpleMessage $message)
    {
        return collect((array) $message->getCc())->map(function ($display, $address) {
            return $display ? [
                'emailAddress' => [
                    'address' => $address,
                    'name' => $display
                ]
            ] : [
                'emailAddress' => [
                    'address' => $address
                ]
            ];
        })->values()->toArray();
    }

    /**
     * Get the "replyTo" payload field for the API request.
     *
     * @param \Swift_Mime_SimpleMessage $message
     * @return string
     */
    protected function getReplyTo(Swift_Mime_SimpleMessage $message)
    {
        return collect((array) $message->getReplyTo())->map(function ($display, $address) {
            return $display ? [
                'emailAddress' => [
                    'address' => $address,
                    'name' => $display
                ]
            ] : [
                'emailAddress' => [
                    'address' => $address
                ]
            ];
        })->values()->toArray();
    }

    /**
     * Get the "Bcc" payload field for the API request.
     *
     * @param \Swift_Mime_SimpleMessage $message
     * @return string
     */
    protected function getBcc(Swift_Mime_SimpleMessage $message)
    {
        return collect((array) $message->getBcc())->map(function ($display, $address) {
            return $display ? [
                'emailAddress' => [
                    'address' => $address,
                    'name' => $display
                ]
            ] : [
                'emailAddress' => [
                    'address' => $address
                ]
            ];
        })->values()->toArray();
    }

    /**
     * Get all of the contacts for the message.
     *
     * @param \Swift_Mime_SimpleMessage $message
     * @return array
     */
    protected function allContacts(Swift_Mime_SimpleMessage $message)
    {
        return array_merge(
            (array) $message->getTo(),
            (array) $message->getCc(),
            (array) $message->getBcc(),
            (array) $message->getReplyTo()
        );
    }

    protected function getAccessToken()
    {
        $guzzle = new \GuzzleHttp\Client();
        $url = 'https://login.microsoftonline.com/' . config('office365mail.tenant') . '/oauth2/v2.0/token';
        $token = json_decode($guzzle->post($url, [
            'form_params' => [
                'client_id' => config('office365mail.client_id'),
                'client_secret' => config('office365mail.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ],
        ])->getBody()->getContents());

        return $token->access_token;
    }
}
