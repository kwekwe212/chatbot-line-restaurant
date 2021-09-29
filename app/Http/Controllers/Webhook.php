<?php

namespace App\Http\Controllers;

use App\Gateway\EventLogGateway;
use App\Gateway\QuestionGateway;
use App\Gateway\UserGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Logger;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;

class Webhook extends Controller
{
    /**
     * @var LINEBot
     */
    private $bot;
    /**
     * @var Request
     */
    private $request;
    /**
     * @var Response
     */
    private $response;
    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var EventLogGateway
     */
    private $logGateway;
    /**
     * @var UserGateway
     */
    private $userGateway;
    /**
     * @var array
     */
    private $user;


    public function __construct(
        Request $request,
        Response $response,
        // CurlHTTPClient $httpClient,
        Logger $logger,
        EventLogGateway $logGateway,
        UserGateway $userGateway,
        QuestionGateway $questionGateway
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->logger = $logger;
        $this->logGateway = $logGateway;
        $this->userGateway = $userGateway;
        $this->questionGateway = $questionGateway;

        // create bot object
        $httpClient = new CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
        $this->bot  = new LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);
    }


    public function __invoke()
    {
        // get request
        $body = $this->request->all();

        // debuging data
        $this->logger->debug('Body', $body);

        // save log
        $signature = $this->request->server('HTTP_X_LINE_SIGNATURE') ?: '-';
        $this->logGateway->saveLog($signature, json_encode($body, true));

        return $this->handleEvents();
    }

    private function handleEvents()
    {
        $data = $this->request->all();

        if (is_array($data['events'])) {
            foreach ($data['events'] as $event) {
                // skip group and room event
                if (!isset($event['source']['userId'])) continue;

                // get user data from database
                $this->user = $this->userGateway->getUser($event['source']['userId']);

                // if user not registered
                if (!$this->user) $this->followCallback($event);
                else {
                    // respond event
                    if ($event['type'] == 'message') {
                        if (method_exists($this, $event['message']['type'] . 'Message')) {
                            $this->{$event['message']['type'] . 'Message'}($event);
                        }
                    } else {
                        if (method_exists($this, $event['type'] . 'Callback')) {
                            $this->{$event['type'] . 'Callback'}($event);
                        }
                    }
                }
            }
        }


        $this->response->setContent("No events found!");
        $this->response->setStatusCode(200);
        return $this->response;
    }

    private function followCallback($event)
    {
        $res = $this->bot->getProfile($event['source']['userId']);
        if ($res->isSucceeded()) {
            $profile = $res->getJSONDecodedBody();

            // create welcome message
            // $message  = "Salam kenal, " . $profile['displayName'] . "!\n";
            $message  = "Halo " . $profile['displayName'] . ", Selamat datang di Restoran!\n";
            $message2 = "Yuk pilih paket menu nya, tapi sebelum itu sekarang kamu di meja nomor berapa ya? (contoh : meja 2).";
            $textMessageBuilder = new TextMessageBuilder($message);
            $textMessageBuilder2 = new TextMessageBuilder($message2);

            // create sticker message
            $stickerMessageBuilder = new StickerMessageBuilder(1, 13);

            // merge all message
            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder);
            $multiMessageBuilder->add($textMessageBuilder2);
            $multiMessageBuilder->add($stickerMessageBuilder);

            // send reply message
            $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);

            // save user data
            $this->userGateway->saveUser(
                $profile['userId'],
                $profile['displayName']
            );
        }
    }


    private function textMessage($event)
    {
        $userMessage = $event['message']['text'];

        // CEK NO MEJA
        if (preg_match("/meja /i", strtolower($userMessage))) {
            if ($this->user['number'] == 0) {
                $nomeja = intval(substr(strtolower($userMessage), 5));
                $text1 = new TextMessageBuilder("Kamu di meja nomor $nomeja");
                $text2 = new TextMessageBuilder("yuk lihat daftar menu dengan kirim pesan 'list menu' ");

                $multiMessageBuilder = new MultiMessageBuilder();
                $multiMessageBuilder->add($text1);
                $multiMessageBuilder->add($text2);

                $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
                $this->userGateway->simpanMeja($this->user['user_id'], $nomeja);
            } else {
                $nomeja = $this->user['nomeja'];
                $textMessageBuilder1 = new TextMessageBuilder("Loh tadi udah ngisi lo! 0x100092");
                $textMessageBuilder2 = new TextMessageBuilder("Kamu tadi di meja $nomeja, yuk lihat daftar menu dengan kirim pesan 'list menu' ");

                $multiMessageBuilder = new MultiMessageBuilder();
                $multiMessageBuilder->add($textMessageBuilder1);
                $multiMessageBuilder->add($textMessageBuilder2);

                $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
            }
        }

        // LIST MENU - FLEX MESSAGE
        elseif (strtolower($userMessage) == 'list menu') {
            if ($this->user['number'] = 0) {
                $this->bot->replyMessage($event['replyToken'], "Hmm, kamu harus isi nomor meja dulu. format balasan (meja 2)");
            } else {
                $httpClient = new CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
                $flexTemplate = file_get_contents("https://botresto-mikhel.herokuapp.com/list_menu.json"); // template flex message
                $httpClient->post(LINEBot::DEFAULT_ENDPOINT_BASE . '/v2/bot/message/reply', [
                    'replyToken' => $event['replyToken'],
                    'messages'   => [
                        [
                            'type'     => 'flex',
                            'altText'  => 'Test Flex Message',
                            'contents' => json_decode($flexTemplate)
                        ]
                    ],
                ]);
            }
        }

        // PESAN / ULANG
        elseif (preg_match("/pesan/i", strtolower($userMessage))) {
            $mes = preg_replace("/pesan /i", '', strtolower($userMessage));

            $user = $this->user['user_id'];
            $name = $this->user['display_name'];
            $menu = $this->user['menu'];
            $nomeja = $this->user['nomeja'];
            $porsi = $this->user['porsi'];
            $jumlahorder = intval($this->user['jumlah_order']) + 1;

            if ($mes == 'ya') {
                $textMessageBuilder1 = new TextMessageBuilder("Oke siap pesanan kamu akan dibuat, tapi jangan lupa bayar dulu ya");
                $textMessageBuilder2 = new TextMessageBuilder("O iya kalau mau pesen kirim nomor meja dulu ya, Terima kasih sudah pesan");
                $stickerMessageBuilder = new StickerMessageBuilder(1, 2);

                $multiMessageBuilder = new MultiMessageBuilder();
                $multiMessageBuilder->add($textMessageBuilder1);
                $multiMessageBuilder->add($textMessageBuilder2);
                $multiMessageBuilder->add($stickerMessageBuilder);

                $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
                $this->userGateway->simpanOrder($user, $name, $menu, $nomeja, $porsi, $jumlahorder);
                $this->userGateway->ulangUsers($user, $jumlahorder);
            } else {
                $this->bot->replyText($event['replyToken'], "Oke, yuk lihat daftar menu dengan kirim pesan 'list menu'");
                $this->userGateway->ulangOrder($user);
            }
        }

        // PESAN BERAPA PORSI
        elseif (preg_match("/\d porsi/i", strtolower($userMessage))) {
            $porsi = preg_replace("/ porsi/i", '', $event['message']['text']);
            $menu = $this->user['menu'];
            // $this->bot->replyText($event['replyToken'], "oke siap $menu untuk $porsi porsi masih ada");

            $total = $this->userGateway->ambilMenu($this->user['menu']);
            $harga = $total['harga'] * intval($porsi);
            $nama = $this->user['display_name'];
            $ket = $total['keterangan'];
            $textMessageBuilder1 = new TextMessageBuilder("oke siap $menu untuk $porsi porsi masih ada");
            $textMessageBuilder2 = new TextMessageBuilder("$nama kamu pesan $porsi porsi $menu isinya $ket dengan total harga $harga");
            $textMessageBuilder3 = new TextMessageBuilder("Pesan sekarang balas dengan kirim 'pesan ya' atau untuk mengulang pesan balas dengan kirim 'pesan ulang'");

            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder1);
            $multiMessageBuilder->add($textMessageBuilder2);
            $multiMessageBuilder->add($textMessageBuilder3);

            $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
            $this->userGateway->simpanPorsi($this->user['user_id'], intval($porsi));
        }

        // PESAN PAKET APA
        elseif (strtolower($userMessage) == "paket a" || "paket b" || "paket c" || "paket d") {
            $paket = $userMessage;
            $textMessageBuilder1 = new TextMessageBuilder("Kamu pilih menu $paket");
            $textMessageBuilder2 = new TextMessageBuilder('Mau beli berapa porsi ya? format balasan (3 porsi)');

            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder1);
            $multiMessageBuilder->add($textMessageBuilder2);

            $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
            $this->userGateway->simpanMenu($this->user['user_id'], $paket);
        }

        // KALO NGGAK ADA
        else {
            $this->bot->replyText($event['replyToken'], "hayoo, mungkin kamu salah kirim");
        }
    }
}
