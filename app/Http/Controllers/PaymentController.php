<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Exception;

class PaymentController extends Controller
{
    public function createTransaction(Request $request) {
        try {
            if ($request->has('bid')) {
                $reference = $request->bid;
                $billing = Billing::findOrFail($reference);
                return view('create-transaction', compact('billing'));
            }
            return view('create-transaction');
        } catch (Exception $e) {
            Log::error('Payment Error - createTransaction: ' . $e->getMessage());
            return redirect()
                ->route('createTransaction')
                ->with('error', 'Unable to load transaction page. Please try again.');
        }
    }

    public function processTransaction(Request $request) {
        try {
            if ($message = $this->missingPayPalCredentialsMessage()) {
                return redirect()
                    ->route('createTransaction')
                    ->with('error', $message);
            }

            // Validate required parameter
            if (!$request->has('bid') || !$request->bid) {
                return redirect()
                    ->route('createTransaction')
                    ->with('error', 'Billing ID is required.');
            }

            $billingId = $request->bid;
            $billing = Billing::findOrFail($billingId);

            // Check if payment already exists
            $existingPayment = $billing->payments()->where('amount', $billing->amount)->first();
            if ($existingPayment) {
                return redirect()
                    ->route('createTransaction', ['bid' => $billingId])
                    ->with('success', 'Payment already completed for this billing.');
            }

            // Validate billing amount
            if (!$billing->amount || $billing->amount <= 0) {
                return redirect()
                    ->route('createTransaction', ['bid' => $billingId])
                    ->with('error', 'Invalid billing amount.');
            }

            // Clear old token before creating new order (in case of retry)
            $billing->update(['token' => null]);

            $provider = $this->buildPayPalClient();

            $response = $provider->createOrder([
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

            // Check for errors in response
            if (isset($response['error']) || isset($response['error_description'])) {
                $errorMessage = $response['error_description'] ?? $response['error'] ?? 'PayPal API error occurred.';
                Log::error('PayPal API Error - processTransaction: ' . json_encode($response));
                return redirect()
                    ->route('createTransaction', ['bid' => $billingId])
                    ->with('error', $errorMessage);
            }

            if (isset($response['id']) && $response['id'] != null) {
                if (isset($response['links']) && is_array($response['links'])) {
                    foreach ($response['links'] as $links) {
                        if (isset($links['rel']) && $links['rel'] == 'approve' && isset($links['href'])) {
                            $queryString = parse_url($links['href'], PHP_URL_QUERY);
                            $params = [];
                            if ($queryString) {
                                parse_str($queryString, $params);
                                if (isset($params['token'])) {
                                    $billing->update(['token' => $params['token']]);
                                }
                            }
                            return redirect()->away($links['href']);
                        }
                    }
                }

                Log::error('PayPal Response Error - No approve link found: ' . json_encode($response));
                return redirect()
                    ->route('createTransaction', ['bid' => $billingId])
                    ->with('error', 'Unable to process payment. Please try again.');
            } else {
                $errorMessage = $response['message'] ?? $response['name'] ?? 'Something went wrong.';
                Log::error('PayPal Order Creation Failed: ' . json_encode($response));
                return redirect()
                    ->route('createTransaction', ['bid' => $billingId])
                    ->with('error', $errorMessage);
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Payment Error - Billing not found: ' . $e->getMessage());
            $billingId = $request->has('bid') ? $request->bid : null;
            return redirect()
                ->route('createTransaction', $billingId ? ['bid' => $billingId] : [])
                ->with('error', 'Billing record not found.');
        } catch (Exception $e) {
            Log::error('Payment Error - processTransaction: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            $billingId = $request->has('bid') ? $request->bid : null;
            return redirect()
                ->route('createTransaction', $billingId ? ['bid' => $billingId] : [])
                ->with('error', 'An error occurred while processing your payment. Please try again.');
        }
    }

    public function successTransaction(Request $request) {
        try {
            if ($message = $this->missingPayPalCredentialsMessage()) {
                return redirect()
                    ->route('createTransaction')
                    ->with('error', $message);
            }

            // Validate token parameter
            if (!$request->has('token') || !$request->token) {
                Log::error('Payment Error - Missing token in successTransaction');
                
                // Try to get billing ID from request if available
                $billingId = $request->has('bid') ? $request->bid : null;
                
                return redirect()
                    ->route('createTransaction', $billingId ? ['bid' => $billingId] : [])
                    ->with('error', 'Payment token is missing. Please try again.');
            }

            $token = $request->token;
            $provider = $this->buildPayPalClient();
            
            $response = $provider->capturePaymentOrder($token);

            // Check for errors in response
            if (isset($response['error']) || isset($response['error_description'])) {
                $errorMessage = $response['error_description'] ?? $response['error'] ?? 'Payment capture failed.';
                Log::error('PayPal Capture Error: ' . json_encode($response));
                
                // Find billing by token to get the billing ID for redirect
                $billing = Billing::where('token', $token)->first();
                $billingId = $billing ? $billing->id : null;
                
                // Clear token on error so user can retry
                if ($billing) {
                    $billing->update(['token' => null]);
                }
                
                return redirect()
                    ->route('createTransaction', $billingId ? ['bid' => $billingId] : [])
                    ->with('error', $errorMessage);
            }

            // Find billing by token
            $billing = Billing::where('token', $token)->first();
            
            if (!$billing) {
                Log::error('Payment Error - Billing not found for token: ' . $token);
                return redirect()
                    ->route('createTransaction')
                    ->with('error', 'Billing record not found. Please contact support.');
            }

            if (isset($response['status']) && $response['status'] == "COMPLETED") {
                // Check if payment already exists to prevent duplicates
                $existingPayment = $billing->payments()->where('amount', $billing->amount)->first();
                
                if (!$existingPayment) {
                    $billing->payments()->create([
                        'amount' => $billing->amount,
                    ]);
                }
                
                // Clear the token after successful payment
                $billing->update(['token' => null]);
                
                return redirect()
                    ->route('createTransaction', ['bid' => $billing->id])
                    ->with('success', 'Transaction Complete.');
            } else {
                $errorMessage = $response['message'] ?? $response['name'] ?? 'Payment was not completed.';
                Log::warning('PayPal Payment Not Completed: ' . json_encode($response));
                
                // Clear token on failure so user can retry
                if ($billing) {
                    $billing->update(['token' => null]);
                }
                
                return redirect()
                    ->route('createTransaction', ['bid' => $billing->id])
                    ->with('error', $errorMessage);
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Payment Error - Billing not found in successTransaction: ' . $e->getMessage());
            // Try to get billing ID from request or token
            $billingId = null;
            if ($request->has('bid')) {
                $billingId = $request->bid;
            } elseif ($request->has('token')) {
                $billing = Billing::where('token', $request->token)->first();
                $billingId = $billing ? $billing->id : null;
            }
            return redirect()
                ->route('createTransaction', $billingId ? ['bid' => $billingId] : [])
                ->with('error', 'Billing record not found. Please contact support.');
        } catch (Exception $e) {
            Log::error('Payment Error - successTransaction: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            // Try to get billing ID from request or token
            $billingId = null;
            if ($request->has('bid')) {
                $billingId = $request->bid;
            } elseif ($request->has('token')) {
                $billing = Billing::where('token', $request->token)->first();
                $billingId = $billing ? $billing->id : null;
            }
            return redirect()
                ->route('createTransaction', $billingId ? ['bid' => $billingId] : [])
                ->with('error', 'An error occurred while completing your payment. Please contact support if the payment was deducted.');
        }
    }

    public function cancelTransaction(Request $request) {
        // Try to get billing ID from token if available
        $billingId = null;
        if ($request->has('token') && $request->token) {
            $billing = Billing::where('token', $request->token)->first();
            if ($billing) {
                $billingId = $billing->id;
                // Clear token when user cancels
                $billing->update(['token' => null]);
            }
        }
        
        return redirect()
            ->route('createTransaction', $billingId ? ['bid' => $billingId] : [])
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
        try {
            $provider = new PayPalClient(config('paypal'));
            $provider->setApiCredentials(config('paypal'));
            $accessToken = $provider->getAccessToken();
            
            // Check if accessToken is an array with error
            if (is_array($accessToken)) {
                // Handle nested error structure
                if (isset($accessToken['error'])) {
                    $errorData = $accessToken['error'];
                    
                    // If error is an array, extract the error message
                    if (is_array($errorData)) {
                        $errorMessage = $errorData['error_description'] ?? $errorData['error'] ?? 'Client Authentication failed';
                    } else {
                        $errorMessage = $errorData;
                    }
                    
                    // Also check for top-level error_description
                    if (isset($accessToken['error_description'])) {
                        $errorMessage = $accessToken['error_description'];
                    }
                    
                    Log::error('PayPal Authentication Error: ' . json_encode($accessToken));
                    throw new Exception('PayPal authentication failed: ' . $errorMessage);
                }
            }
            
            // Check if accessToken is empty or false
            if (empty($accessToken)) {
                Log::error('PayPal Authentication Error: Empty access token received');
                throw new Exception('PayPal authentication failed: No access token received. Please check your credentials.');
            }
            
            return $provider;
        } catch (Exception $e) {
            // Only log if it's not already a PayPal authentication error
            if (strpos($e->getMessage(), 'PayPal authentication failed') === false) {
                Log::error('PayPal Client Build Error: ' . $e->getMessage());
            }
            
            // Re-throw if it's already a formatted error
            if (strpos($e->getMessage(), 'PayPal authentication failed') !== false) {
                throw $e;
            }
            
            throw new Exception('Unable to connect to PayPal. Please check your credentials and try again.');
        }
    }
}
