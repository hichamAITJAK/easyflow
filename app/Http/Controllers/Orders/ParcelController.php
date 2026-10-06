<?php

namespace App\Http\Controllers\Orders;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\BuildsTableQuery;
use App\Http\Controllers\Concerns\ScopesAgentAccess;
use App\Http\Controllers\Controller;
use App\Models\DeliveryAccount;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ParcelController extends Controller
{
    use BuildsTableQuery;
    use ScopesAgentAccess;

    /**
     * Columns the parcels table may be sorted by, keyed to their allowed
     * query-string `sort` value.
     */
    private const SORTABLE = ['shipped_at', 'delivery_status', 'delivery_cost', 'total_amount'];

    /**
     * Columns searched by the `search` query param.
     */
    private const SEARCHABLE = ['reference', 'courier_tracking_number'];

    /**
     * List orders that have already been registered as a parcel with a
     * courier (courier_tracking_number is set), for tracking their delivery
     * progress independently of the confirmation-focused orders list.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $businessId = $user->business_id;

        // A confirmation agent sees the parcels of their own orders. A
        // fulfilment agent is never assigned orders, so for them the list
        // is the business's parcels, narrowed by their store grants.
        $query = $user->role === UserRole::FULFILMENT_AGENT
            ? Order::query()->when(
                $this->scopedStoreIds($user),
                fn ($query, array $storeIds) => $query->whereIn('store_id', $storeIds),
            )
            : $this->applyOrderAssignmentScope(Order::query(), $user);

        $query = $query
            ->with(['deliveryAccount.courier:id,name,slug'])
            ->where('business_id', $businessId)
            ->whereNotNull('courier_tracking_number')
            ->when($request->string('delivery_status')->toString(), fn ($query, $status) => $query->where('delivery_status', $status))
            ->when($this->resolveIdList($request, 'delivery_account_ids', 'delivery_account_id'), fn ($query, array $accountIds) => $query->whereIn('delivery_account_id', $accountIds))
            ->when($request->date('date_from'), fn ($query, $date) => $query->whereDate('shipped_at', '>=', $date))
            ->when($request->date('date_to'), fn ($query, $date) => $query->whereDate('shipped_at', '<=', $date));

        $query = $this->applySearch($query, $request, self::SEARCHABLE, hashColumn: 'customer_phone_hash');
        $query = $this->applySort($query, $request, self::SORTABLE, default: 'shipped_at');

        $parcels = $query->paginate($this->resolvePerPage($request))->withQueryString();

        $parcels->getCollection()->each->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        return Inertia::render('parcels/index', [
            'parcels' => $parcels,
            'filters' => [
                ...$request->only(['search', 'sort', 'direction', 'per_page', 'delivery_status', 'date_from', 'date_to']),
                'delivery_account_ids' => $this->idListParam($request, 'delivery_account_ids', 'delivery_account_id'),
            ],
            'deliveryAccounts' => DeliveryAccount::where('business_id', $businessId)
                ->with('courier:id,name,slug')
                ->get(['id', 'courier_id', 'label']),
        ]);
    }
}
