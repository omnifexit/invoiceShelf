<?php

use App\Http\Controllers\V1\Admin\Customer\CustomersController;
use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->withHeaders([
        'company' => $user->companies()->first()->id,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );
});

test('get customers', function () {
    $response = getJson('api/v1/customers?page=1');

    $response->assertOk();
});

test('customer stats', function () {
    $customer = Customer::factory()->create();

    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
    ]);

    $response = getJson("api/v1/customers/{$customer->id}/stats");

    $response->assertStatus(200);
});

test('create customer', function () {
    $customer = Customer::factory()->raw([
        'shipping' => [
            'name' => 'newName',
            'address_street_1' => 'address',
        ],
        'billing' => [
            'name' => 'newName',
            'address_street_1' => 'address',
        ],
    ]);

    postJson('api/v1/customers', $customer)
        ->assertOk();

    $this->assertDatabaseHas('customers', [
        'name' => $customer['name'],
        'email' => $customer['email'],
    ]);
});

test('create customer with credit limit', function () {
    $customer = Customer::factory()->raw(['credit_limit' => 150000]);

    postJson('api/v1/customers', $customer)
        ->assertOk()
        ->assertJsonPath('data.credit_limit', 150000);

    $this->assertDatabaseHas('customers', [
        'email' => $customer['email'],
        'credit_limit' => 150000,
    ]);
});

test('create customer without credit limit stores null', function () {
    $customer = Customer::factory()->raw();

    postJson('api/v1/customers', $customer)->assertOk();

    $this->assertDatabaseHas('customers', [
        'email' => $customer['email'],
        'credit_limit' => null,
    ]);
});

test('credit limit must be a non-negative integer', function () {
    $customer = Customer::factory()->raw(['credit_limit' => -1]);

    postJson('api/v1/customers', $customer)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['credit_limit']);
});

test('update customer can clear credit limit', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);

    $payload = Customer::factory()->raw(['credit_limit' => null]);

    putJson('api/v1/customers/'.$customer->id, $payload)->assertOk();

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'credit_limit' => null,
    ]);
});

test('store validates using a form request', function () {
    $this->assertActionUsesFormRequest(
        CustomersController::class,
        'store',
        CustomerRequest::class
    );
});

test('get customer', function () {
    $customer = Customer::factory()->create();

    $response = getJson("api/v1/customers/{$customer->id}");

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'name' => $customer['name'],
        'email' => $customer['email'],
    ]);

    $response->assertOk();
});

test('update customer', function () {
    $customer = Customer::factory()->create();

    $customer1 = Customer::factory()->raw([
        'shipping' => [
            'name' => 'newName',
            'address_street_1' => 'address',
        ],
        'billing' => [
            'name' => 'newName',
            'address_street_1' => 'address',
        ],
    ]);

    $response = putJson('api/v1/customers/'.$customer->id, $customer1);

    $customer1 = collect($customer1)
        ->only([
            'email',
        ])
        ->merge([
            'creator_id' => Auth::id(),
        ])
        ->toArray();

    $response->assertOk();

    $this->assertDatabaseHas('customers', $customer1);
});

test('update validates using a form request', function () {
    $this->assertActionUsesFormRequest(
        CustomersController::class,
        'update',
        CustomerRequest::class
    );
});

test('search customers', function () {
    $filters = [
        'page' => 1,
        'limit' => 15,
        'search' => 'doe',
        'email' => '.com',
    ];

    $queryString = http_build_query($filters, '', '&');

    $response = getJson('api/v1/customers?'.$queryString);

    $response->assertOk();
});

test('delete multiple customer', function () {
    $customers = Customer::factory()->count(4)->create();

    $ids = $customers->pluck('id');

    $data = [
        'ids' => $ids,
    ];

    $response = postJson('api/v1/customers/delete', $data);

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
        ]);
});
