<?php

namespace Modules\Stripe\Http\Controllers;

use App\Models\PaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

use App\Models\PaymentGateway;
use App\Models\User;
use App\Http\Requests\UpdateUserProfile;
use Image;
use Storage;
use GeoIP;
use Date;
use Log;

class ConnectController extends Controller
{

    /**
     * Store a newly created resource in storage.
     * @param  Request $request
     * @return Response
     */
    public function store(Request $request)
    {
        //create a custom account
        $provider = PaymentProvider::where('key', 'stripe')->first();
        \Stripe\Stripe::setApiKey(\Crypt::decryptString($provider->extra_attributes->secret_key));
        $user = auth()->user();
        $stripe_info = $user->payment_gateway('stripe');
        if(!$stripe_info) {
            try {
                $account = \Stripe\Account::create([
                    "type" => "custom",
                    "country" => $request->input('country'),
                    "email" => 'test-' . $request->input('country') . '-' . auth()->user()->email
                ]);
            } catch (\Exception $e) {
                return ['status' => false, 'error' => $e->getMessage()];
            }
        } else {
            $account = \Stripe\Account::retrieve($stripe_info->gateway_id);
        }

        $payment_gateway = PaymentGateway::firstOrCreate([
            'name' => 'stripe',
            'gateway_id' => $account->id,
            'user_id' => $user->id
        ]);
        $is_new = $payment_gateway->wasRecentlyCreated;

        if($request->input('city'))
            $account->legal_entity->address->city = $request->input('city');
        if($request->input('country'))
            $account->legal_entity->address->country = $request->input('country');
        if($request->input('address_line_1'))
            $account->legal_entity->address->line1 = $request->input('address_line_1');
        if($request->input('postal_code'))
            $account->legal_entity->address->postal_code = $request->input('postal_code');
        if($request->input('state'))
            $account->legal_entity->address->state = $request->input('state');

        if($request->input('dob_day'))
            $account->legal_entity->dob->day = $request->input('dob_day');
        if($request->input('dob_month'))
            $account->legal_entity->dob->month = $request->input('dob_month');
        if($request->input('dob_year'))
            $account->legal_entity->dob->year = $request->input('dob_year');

        if($request->input('first_name'))
            $account->legal_entity->first_name = $request->input('first_name');
        if($request->input('last_name'))
            $account->legal_entity->last_name = $request->input('last_name');

        $account->legal_entity->type = 'individual';

        if($request->input('external_account'))
            $account->external_account = $request->input('external_account');

        if(!$account->tos_acceptance->date) {
            $account->tos_acceptance->date = time();
            $account->tos_acceptance->ip = '80.189.218.119';
        }

        try {
            $account->save();
        } catch (\Exception $e) {
            return ['status' => false, 'error' => $e->getMessage()];
        }
        $payment_gateway->gateway_id = $account->id;
        $payment_gateway->token = $request->input('external_account');
        $payment_gateway->metadata = $account;
        $payment_gateway->save();

        $user->can_accept_payments = true;
        $user->save();

        if($is_new) {
            alert()->success(__('Awesome! You can now accept payments and start selling.'));
        } else {
            alert()->success(__('Successfully updated!'));
        }

        return ['status' => true, 'account' => $account, 'redirect' => route('account.bank-account.index')];
    }


}
