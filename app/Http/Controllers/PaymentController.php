<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\Payment;
use Illuminate\Http\Request;
use Srmklive\PayPal\Services\PayPal as PayPalClient;

class PaymentController extends Controller
{
    public function createTransaction(Request $request) {
        if ($request->has('bid')) {
            $reference = $request->bid;
            $billing = Billing::findOrFail($reference);
            return view('create-transaction', compact('billing'));
        }
        return view('create-transaction');
    }

    public function processTransaction(Request $request) {
        if ($message = $this->missingPayPalCredentialsMessage()) {
            return redirect()
                ->route('createTransaction')
                ->with('error', $message);
        }

        $provider = $this->buildPayPalClient();
        $billingId = $request->bid;
        $billing = Billing::findOrFail($billingId);

        $response =  $provider->createOrder([
            "intent" => "CAPTURE",
            "application_context" => [
                "return_url" => route('successTransaction'),
                "cancel_url" => route('cancelTransaction'),
            ],
            "purchase_units" => [
                0 => [
                    "amount" => [
                        "currency_code" => "PHP",
                        "value" => number_format($billing->amount, 2, '.', ''),
                    ]
                ]
            ]
            ]);

            if (isset($response['id']) && $response['id'] != null) {
                foreach ($response['links'] as $links) {
                    if ($links['rel'] == 'approve') {
                        $queryString = parse_url($links['href'], PHP_URL_QUERY);
                        $params = [];
                        parse_str($queryString, $params);
                        $billing->update(['token' => $params['token']]);
                        return redirect()->away($links['href']);
                    }
                }

                return redirect()
                    ->route('createTransaction')
                    ->with('error', 'Something went wrong.');
            } else {
                return redirect()
                    ->route('createTransaction')
                    ->with('error', $response['message'] ?? 'Something went wrong.');
            }
    }

    public function successTransaction(Request $request) {
        if ($message = $this->missingPayPalCredentialsMessage()) {
            return redirect()
                ->route('createTransaction')
                ->with('error', $message);
        }

        $provider = $this->buildPayPalClient();
        $token = $request->token;
        $response = $provider->capturePaymentOrder($token);
        $billing = Billing::whereToken($token)->firstOrFail();

        if (isset($response['status']) && $response['status'] == "COMPLETED") {
            $billing->payments()->create([
                'amount' => $billing->amount,
            ]);
            return redirect()
                ->route('createTransaction')
                ->with('success', 'Transaction Complete.');
        } else {
            return redirect()
                ->route('createTransaction')
                ->with('error', $response['message'] ?? 'Something went wrong.');
        }
    }

    public function cancelTransaction(Request $request) {
        return redirect()
            ->route('createTransaction')
            ->with('error', 'You have canceled the transaction.');
    }

    protected function missingPayPalCredentialsMessage(): ?string
    {
        $mode = config('paypal.mode', 'sandbox');
        $clientId = config("paypal.$mode.client_id");
        $clientSecret = config("paypal.$mode.client_secret");

        if (blank($clientId) || blank($clientSecret)) {
            $environment = strtoupper($mode);

            return "PayPal {$environment} credentials are missing. Please set PAYPAL_{$environment}_CLIENT_ID and PAYPAL_{$environment}_CLIENT_SECRET in your environment file.";
        }

        return null;
    }

    protected function buildPayPalClient(): PayPalClient
    {
        $provider = new PayPalClient(config('paypal'));
        $provider->setApiCredentials(config('paypal'));
        $provider->getAccessToken();

        return $provider;
    }
}
