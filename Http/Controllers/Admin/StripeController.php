<?php

namespace Modules\Stripe\Http\Controllers\Admin;

use App\Models\PaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class StripeController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Response
     */
    public function index()
    {
        $key = 'stripe';
        $module = \Module::findByAlias('stripe');
        $payment_provider = PaymentProvider::where('key', $key)->first();
        if(!$payment_provider) {
            $default_provider = collect(json_decode(file_get_contents( module_path($module->name) .'/Resources/details.json' )))->toArray();
            #dd($default_provider);

            PaymentProvider::create($default_provider);

            $payment_provider = PaymentProvider::where('key', $key)->first();
        }
        #dd($payment_provider);

        return redirect()->route('panel.payments.edit', ['payment_provider' => $payment_provider->id]);
    }

    /**
     * Show the form for creating a new resource.
     * @return Response
     */
    public function create()
    {
        return view('stripe::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param  Request $request
     * @return Response
     */
    public function store(Request $request)
    {
        dd(5);
    }

    /**
     * Show the specified resource.
     * @return Response
     */
    public function show()
    {
        return view('stripe::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @return Response
     */
    public function edit()
    {
        return view('stripe::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param  Request $request
     * @return Response
     */
    public function update(Request $request)
    {
    }

    /**
     * Remove the specified resource from storage.
     * @return Response
     */
    public function destroy()
    {
    }
}
