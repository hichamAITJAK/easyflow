<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\Courier;
use App\Enums\DeliveryAccountStatus;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Services\Connectivity\Ameex\AmeexClient;
use App\Services\Connectivity\Coliix\ColiixClient;
use App\Services\Connectivity\ForceLog\ForceLogClient;
use App\Services\Connectivity\OzonExpress\OzonExpressClient;
use App\Services\Connectivity\Sendit\AuthService;
use App\Services\Connectivity\Sendit\DistrictService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

trait ConnectsDeliveryAccount
{
    /**
     * Test the given courier's credentials, and if they check out, store a new
     * delivery account for the business. Returns a redirect back with field
     * errors if the credentials are rejected or the courier can't be reached.
     *
     * A courier's API gives no stable id to detect "you already connected
     * this exact account" (Sendit's key pair and OzonExpress's ozon_id can
     * both be regenerated for the same real account), so a business can
     * connect multiple accounts per courier and the user-entered label is
     * what's enforced unique per (business, courier) instead — same
     * QueryException-23000-catch pattern CreatesConnectedStore uses for
     * duplicate store connections.
     *
     * @param  array<string, mixed>  $data
     */
    private function testAndCreateDeliveryAccount(int $businessId, DeliveryCourrier $courier, array $data): DeliveryAccount|RedirectResponse
    {
        $status = DeliveryAccountStatus::UNVERIFIED;

        if ($courier->slug === Courier::SENDIT->value) {
            $credentials = ['public_key' => $data['public_key'], 'secret_key' => $data['secret_key']];

            try {
                $login = (new AuthService(''))->login($data['public_key'], $data['secret_key']);
                $token = $login['data']['token'] ?? throw new RuntimeException('Sendit login did not return a token');

                $credentials['token'] = $token;

                (new DistrictService($token))->getDistricts();
                $status = DeliveryAccountStatus::ACTIVE;
            } catch (ConnectionException) {
                return back()->withErrors(['secret_key' => 'Could not reach Sendit — check your connection and try again.']);
            } catch (RequestException $e) {
                $message = $e->response->status() === 401
                    ? 'Those Sendit credentials were rejected. Double-check them and try again.'
                    : 'Sendit couldn\'t process that request right now. Try again in a moment.';

                return back()->withErrors(['secret_key' => $message]);
            } catch (RuntimeException $e) {
                return back()->withErrors(['secret_key' => $e->getMessage()]);
            }
        } elseif ($courier->slug === Courier::COLIIX->value) {
            $credentials = ['client_id' => $data['client_id'], 'token' => $data['api_key']];

            // Coliix has no dedicated "check credentials" endpoint, so this
            // piggybacks on "track" with a fake tracking number — see
            // ColiixClient::verifyCredentials() for why an invalid token is
            // expected to come back as status 204 regardless of the
            // (nonexistent) tracking number.
            $client = new ColiixClient($data['api_key']);

            if ($client->verifyCredentials()) {
                $status = DeliveryAccountStatus::ACTIVE;
            } else {
                return back()->withErrors(['api_key' => 'Those Coliix credentials were rejected. Double-check them and try again.']);
            }
        } elseif ($courier->slug === Courier::AMEEX->value) {
            $credentials = ['api_id' => $data['api_id'], 'api_key' => $data['api_key']];

            // Ameex authenticates with an id/key header pair and has no
            // dedicated "check credentials" endpoint, so this piggybacks on
            // the authenticated parcel-status list. Note Ameex answers
            // everything with HTTP 200 — see AmeexClient::verifyCredentials()
            // for why only the body's `login` layer decides this.
            $client = new AmeexClient($data['api_id'], $data['api_key']);

            if ($client->verifyCredentials()) {
                $status = DeliveryAccountStatus::ACTIVE;
            } else {
                return back()->withErrors(['api_key' => 'Those Ameex credentials were rejected. Double-check them and try again.']);
            }
        } elseif ($courier->slug === Courier::FORCELOG->value) {
            $credentials = ['api_key' => $data['api_key']];

            // ForceLog authenticates with a single API key in the X-API-Key
            // header and has no dedicated "check credentials" endpoint, so
            // this piggybacks on the authenticated /customer/Cities list —
            // see ForceLogClient::verifyCredentials() for why /health is
            // deliberately not used (it is unauthenticated, so it would
            // accept any key at all).
            $client = new ForceLogClient($data['api_key']);

            if ($client->verifyCredentials()) {
                $status = DeliveryAccountStatus::ACTIVE;
            } else {
                return back()->withErrors(['api_key' => 'Those ForceLog credentials were rejected. Double-check them and try again.']);
            }
        } else {
            $credentials = ['ozon_id' => $data['ozon_id'], 'api_key' => $data['api_key']];

            // OzonExpress has no dedicated "check credentials" endpoint, so
            // this piggybacks on /tracking with a fake tracking number —
            // see OzonExpressClient::verifyCredentials() for why that's
            // read independently of the (expectedly failing) tracking
            // lookup itself, via CHECK_API.RESULT rather than TRACKING.RESULT.
            $client = new OzonExpressClient($data['ozon_id'], $data['api_key']);

            if ($client->verifyCredentials()) {
                $status = DeliveryAccountStatus::ACTIVE;
            } else {
                return back()->withErrors(['api_key' => 'Those OzonExpress credentials were rejected. Double-check them and try again.']);
            }
        }

        try {
            return DeliveryAccount::create([
                'business_id' => $businessId,
                'courier_id' => $courier->id,
                'collect_city_id' => $data['collect_city_id'],
                'label' => $data['label'],
                'api_credentials' => json_encode($credentials),
                'status' => $status,
                'is_default' => ! DeliveryAccount::where('business_id', $businessId)->exists(),
            ]);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return back()->withErrors(['label' => __('This label is already used for another :courier account.', ['courier' => $courier->name])]);
        }
    }
}
