<?php

namespace Modules\Stripe\Http\Controllers;

use App\Models\CheckoutSession;
use App\Models\PaymentGateway;
use App\Models\PaymentProvider;
use App\Models\Order;
use Hashids;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Requests\UpdateUserProfile;
use Image;
use Storage;
use GeoIP;
use Date;
use URL;
use App\Support\PaypalClassic;
use Socialite;
use App\Events\OrderPlaced;

class PaymentController extends Controller
{

    public function accept($order) {
        try {
            $provider = PaymentProvider::where('key', 'stripe')->first();
            \Stripe\Stripe::setApiKey(\Crypt::decryptString($provider->extra_attributes->secret_key));
            $charge = \Stripe\Charge::retrieve($order->authorization_id, ["stripe_account" => $order->payment_gateway->gateway_id]);
            $result = $charge->capture();
            return $charge->id;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function decline($order) {
        try {
            //\Stripe\Stripe::setApiKey(config('marketplace.stripe_secret_key'));
            $provider = PaymentProvider::where('key', 'stripe')->first();
            \Stripe\Stripe::setApiKey(\Crypt::decryptString($provider->extra_attributes->secret_key));
            $charge = \Stripe\Charge::retrieve($order->authorization_id, ["stripe_account" => $order->payment_gateway->gateway_id]);

            $refund = \Stripe\Refund::create(array(
                "charge" => $charge->id
            ), ["stripe_account" => $order->payment_gateway->gateway_id]);

            return $refund->id;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function index($session, Request $request)
    {
        $listing = $session->listing;

        $widget = '\App\Widgets\Order\\'.studly_case($listing->pricing_model->widget).'Widget';
        $widget = new $widget();
        $result = $widget->calculate_price($listing, $session->request);
        #dd($result);
        $data = [];
        $data['pricing'] = $result;
        return view('checkout.stripe', $data);
    }

    private function getCustomer($payment_gateway) {
        $provider = PaymentProvider::where('key', 'stripe')->first();
        \Stripe\Stripe::setApiKey(\Crypt::decryptString($provider->extra_attributes->secret_key));
        $customers = \Stripe\Customer::all([
            "limit" => 30,
            "email" => auth()->user()->email
        ]);

        if( collect($customers->data)->count() ) {
            return collect($customers->data)->sortBy('created')->sortByDesc('subscriptions.total_count')->first()->id;
        }

        return false;
    }

    private function createOrUpdateCustomer($user, $token, $payment_gateway) {
        $provider = PaymentProvider::where('key', 'stripe')->first();
        \Stripe\Stripe::setApiKey(\Crypt::decryptString($provider->extra_attributes->secret_key));

        $membership_stripe_customer = $this->getCustomer($payment_gateway);

        if(!$membership_stripe_customer) {
            $customer = \Stripe\Customer::create([
                'email' => auth()->user()->email,
                'source' => $token,
            ]);
            $user->membership_stripe_customer = $customer->id;
            $user->save();
        } else {

            $customer = \Stripe\Customer::retrieve($membership_stripe_customer);
            $customer->source = $token;
            $customer->save();

        }

        return $customer;
    }

    public function store($session, Request $request)
    {
        $stripeToken = json_decode($request->input('stripeToken'));
        $is_ajax = false;

        if($request->wantsJson()){
            $is_ajax = true;
        }

        try {
            $provider = PaymentProvider::where('key', 'stripe')->first();
            \Stripe\Stripe::setApiKey(\Crypt::decryptString($provider->extra_attributes->secret_key));

            #calculate the real price of the order
            $listing = $session->listing;
            #calculate the real price of the order
            $widget = '\App\Widgets\Order\\'.studly_case($listing->pricing_model->widget).'Widget';
            $widget = new $widget();
            $validation_result = $widget->calculate_price($listing, $session->request);
            #dd($request->all());

            $payment_gateway = $listing->user->payment_gateway('stripe');

            if(!$payment_gateway) {
                $error = __("This user cannot accept payments currently. No funds will be taken. Please contact the seller directly.");
                return redirect()->route( 'checkout.error', ['message' => $error]);
            }

            #create customer to charge
            $customer = $this->createOrUpdateCustomer(auth()->user(), $stripeToken->id, $payment_gateway);
            /*$customer = \Stripe\Customer::create(array(
                'email' => auth()->user()->email,
                'source'  => $stripeToken->id,
            ));*/

            #create a token
            $token = \Stripe\Token::create(array(
                "customer" => $customer->id,
            ), ["stripe_account" => $payment_gateway->gateway_id]);

            $quantity = $request->input('quantity', 1);
            #charge the customer and send funds to seller account
            $charge = \Stripe\Charge::create(array(
                'amount'  	 		=> intval($validation_result['total']*100),
                'currency' 			=> $listing->currency,
                "description" 		=> $listing->title . " x".$quantity,
                "capture" 			=> false,
                "application_fee" 	=> intval($validation_result['service_fee']*100),
                "source" 			=> $token->id,
                'receipt_email'     => $request->input('email', auth()->user()->email),
            ), ["stripe_account" 	=> $payment_gateway->gateway_id]);

            #print_r($charge);
            $order = new Order();
            if(auth()->check()) {
                $order->user_id = auth()->user()->id;
            }
            $order->service_fee = $validation_result['service_fee'];
            $order->payment_gateway_id = $payment_gateway->id;
            $order->amount = $validation_result['total'];
            $order->currency = $listing->currency;
            $order->authorization_id = $charge->id;
            $order->capture_id = null;
            $order->processor = 'stripe';

            $order->seller_id = $listing->user->id;
            $order->listing_id = $listing->id;
            $order->token = $stripeToken;
            $order->listing_options = collect($session->request)->except([
                'card', 'token', '_token'
            ]);

            $order->user_choices = $validation_result['user_choice'];
            $order->customer_details = collect($session->request)->only([
                'card.name', 'card.address_line1', 'card.address_line2', 'card.address_city',
                'card.address_state', 'card.address_zip', 'card.address_country', 'card.email', 'card.phone'
            ]);

            $order->shipping_address = $session->extra_attributes['shipping_address'];
            $order->billing_address = $session->extra_attributes['billing_address'];
            $order->save();

            $charge->metadata = ['order_id' => $order->id];
            $charge->save();


            //now decrease listing quantity
            event(new OrderPlaced($order));
            alert()->success(__('Your order was placed successfully. Please note that funds will only be taken once the seller confirms the order.'));
            if($is_ajax) {
                return ['status' => true, 'order' => $order];
            }
            return redirect()->route( 'account.purchase-history.index');
        } catch (\Stripe\Error\Base $e) {
            if($is_ajax) {
                return response()->json(['status' => false, 'message' => $e->getMessage()], 401);
            }
            return redirect()->route( 'checkout.error', ['message' => $e->getMessage()]);
        } catch (\Exception $e) {
            if($is_ajax) {
                return response()->json(['status' => false, 'message' => $e->getMessage()], 401);
            }
            dd($e);
            return redirect()->route( 'checkout.error', ['message' => $e->getMessage()]);
        }

        #never executes
        return redirect()->route( 'checkout.error', ['message' => "Something went wrong"]);
    }

    public function cancel(Request $request)
    {
        dd($request->all());
        $listing = Listing::find($request->input('listing'));
        if($listing) {
            return redirect(route("listing", ['listing' => $listing, 'slug' => $listing->slug]));
        }
        return redirect(route("browse"));
    }


}
