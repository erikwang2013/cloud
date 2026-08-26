# Fase 1: Fluxo Principal de Transações — Plano de Implementação

> **Para agentes de trabalho:** SUB-HABILIDADE OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano tarefa por tarefa.

**Objetivo:** implementar o ciclo completo de transação, do registro/login do usuário à conclusão do pagamento. O usuário pode se registrar, navegar por produtos, fazer pedidos, escolher a forma de pagamento e concluir o pagamento.

**Arquitetura:** cada módulo em diretório próprio (controller/service/model), orientado a eventos (o evento OrderPaid dispara os fluxos seguintes), fila Redis para tarefas assíncronas.

**Stack Tecnológica:** PHP 8.2+, webman, Eloquent ORM, Redis, Stripe PHP SDK

---

### Tarefa 1.1: Registro e login de usuário

**Arquivos:**
- Criar: `service/app/user/controller/AuthController.php`
- Criar: `service/app/user/service/AuthService.php`
- Criar: `service/app/user/model/User.php`
- Criar: `service/app/user/model/RefreshToken.php`
- Criar: `tests/User/AuthTest.php`

- [ ] **Passo 1: Criar o model User**

```php
<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;

    protected $fillable = ['email', 'phone', 'password_hash', 'language', 'currency', 'timezone', 'status', 'role'];

    protected $hidden = ['password_hash', 'deleted_at'];

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function kyc()
    {
        return $this->hasOne(UserKyc::class);
    }

    public function balances()
    {
        return $this->hasMany(UserBalance::class);
    }

    public function addresses()
    {
        return $this->hasMany(UserAddress::class);
    }
}
```

- [ ] **Passo 2: Criar o AuthService**

```php
<?php
namespace App\User\Service;

use App\User\Model\User;
use App\User\Model\UserProfile;
use App\User\Model\UserBalance;
use App\User\Model\RefreshToken;
use Common\Auth\JwtAuth;
use Common\Helper\Response;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    private JwtAuth $jwt;

    public function __construct()
    {
        $this->jwt = new JwtAuth();
    }

    public function register(array $data): array
    {
        $minLength = config('auth.password.min_length');

        if (strlen($data['password']) < $minLength) {
            throw new \InvalidArgumentException('Password too short');
        }

        if (!empty($data['email']) && User::where('email', $data['email'])->exists()) {
            throw new \InvalidArgumentException('Email already registered');
        }

        if (!empty($data['phone']) && User::where('phone', $data['phone'])->exists()) {
            throw new \InvalidArgumentException('Phone already registered');
        }

        $user = User::create([
            'email'         => $data['email'] ?? null,
            'phone'         => $data['phone'] ?? null,
            'password_hash' => Hash::make($data['password'], ['cost' => config('auth.password.cost')]),
            'language'      => $data['language'] ?? config('i18n.default_locale'),
            'currency'      => $data['currency'] ?? 'USD',
            'status'        => 'active',
            'role'          => 'user',
        ]);

        UserProfile::create(['user_id' => $user->id, 'country' => $data['country'] ?? null]);
        UserBalance::create(['user_id' => $user->id, 'currency' => 'USD']);
        UserBalance::create(['user_id' => $user->id, 'currency' => 'CNY']);

        return $this->issueTokens($user->id, 'user');
    }

    public function login(string $login, string $password, string $deviceFingerprint): array
    {
        $user = User::where('email', $login)->orWhere('phone', $login)->first();

        if (!$user || !Hash::check($password, $user->password_hash)) {
            throw new \InvalidArgumentException('Invalid credentials');
        }

        if ($user->status !== 'active') {
            throw new \InvalidArgumentException('Account is not active');
        }

        if ($this->isLoginLocked($user->id)) {
            throw new \InvalidArgumentException('Account temporarily locked, try again later');
        }

        return $this->issueTokens($user->id, $user->role, $deviceFingerprint);
    }

    public function refreshToken(string $refreshToken, string $deviceFingerprint): array
    {
        try {
            $payload = $this->jwt->verify($refreshToken);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid refresh token');
        }

        if ($payload->type !== 'refresh') {
            throw new \InvalidArgumentException('Invalid token type');
        }

        $stored = RefreshToken::where('token_hash', hash('sha256', $refreshToken))
            ->where('revoked', false)
            ->first();

        if (!$stored) {
            throw new \InvalidArgumentException('Token revoked or not found');
        }

        if ($stored->device_fingerprint !== $deviceFingerprint) {
            // Suspicious: device changed, revoke all tokens for this user
            $this->jwt->revokeAllUserTokens($payload->sub);
            throw new \InvalidArgumentException('Device mismatch, all tokens revoked');
        }

        // Revoke old refresh token (rotation)
        $stored->update(['revoked' => true]);

        $user = User::findOrFail($payload->sub);
        return $this->issueTokens($user->id, $user->role, $deviceFingerprint);
    }

    private function issueTokens(int $userId, string $role, string $deviceFingerprint = ''): array
    {
        $accessToken  = $this->jwt->issueAccessToken($userId, $role);
        $refreshToken = $this->jwt->issueRefreshToken($userId);

        RefreshToken::create([
            'user_id'            => $userId,
            'token_hash'         => hash('sha256', $refreshToken),
            'device_fingerprint' => $deviceFingerprint,
            'expires_at'         => date('Y-m-d H:i:s', time() + config('auth.jwt.refresh_ttl')),
        ]);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => config('auth.jwt.access_ttl'),
            'token_type'    => 'Bearer',
        ];
    }

    private function isLoginLocked(int $userId): bool
    {
        $key = "login_lock:{$userId}";
        return Redis::exists($key);
    }

    public function recordFailedLogin(string $login): void
    {
        $key = "login_failed:" . sha1($login);
        $count = Redis::incr($key);
        Redis::expire($key, 900); // 15min window

        if ($count >= 5) {
            $user = User::where('email', $login)->orWhere('phone', $login)->first();
            if ($user) {
                Redis::setex("login_lock:{$user->id}", 900, '1');
            }
        }
    }
}
```

- [ ] **Passo 3: Criar o AuthController**

```php
<?php
namespace App\User\Controller;

use App\User\Service\AuthService;
use Common\Helper\Response;
use Common\Security\AuditLogger;

class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function register($request)
    {
        $data = $request->all();
        if (empty($data['password']) || (empty($data['email']) && empty($data['phone']))) {
            return json(Response::error(422, 'Email or phone required, and password required'));
        }

        try {
            $tokens = $this->auth->register($data);
            AuditLogger::record('user_registered', ['user_id' => null], $request);
            return json(Response::success($tokens, I18n::trans('auth.register_success')));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        }
    }

    public function login($request)
    {
        $login    = $request->input('login');
        $password = $request->input('password');
        $deviceFp = $this->deviceFingerprint($request);

        if (empty($login) || empty($password)) {
            return json(Response::error(422, 'Login and password required'));
        }

        try {
            $tokens = $this->auth->login($login, $password, $deviceFp);
            AuditLogger::record('user_login', ['user_id' => null], $request);
            return json(Response::success($tokens, I18n::trans('auth.login_success')));
        } catch (\InvalidArgumentException $e) {
            $this->auth->recordFailedLogin($login);
            AuditLogger::record('login_failed', ['input' => json_encode(['login' => $login])], $request);
            return json(Response::error(401, $e->getMessage()));
        }
    }

    public function refresh($request)
    {
        $refreshToken = $request->input('refresh_token');
        $deviceFp     = $this->deviceFingerprint($request);

        try {
            $tokens = $this->auth->refreshToken($refreshToken, $deviceFp);
            return json(Response::success($tokens));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(401, $e->getMessage()));
        }
    }

    private function deviceFingerprint($request): string
    {
        $ua     = $request->header('User-Agent', '');
        $ipCidr = substr($request->getRealIp(), 0, strrpos($request->getRealIp(), '.'));
        return hash('sha256', $ua . $ipCidr);
    }
}
```

- [ ] **Passo 4: Criar o teste**

```php
<?php
namespace Tests\User;

use PHPUnit\Framework\TestCase;
use App\User\Service\AuthService;

class AuthTest extends TestCase
{
    public function testRegisterCreatesUser()
    {
        // Mock DB, test registration flow
        $data = ['email' => 'test@example.com', 'password' => 'Test1234', 'country' => 'US'];
        $result = $service->register($data);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertEquals('Bearer', $result['token_type']);
    }
}
```

- [ ] **Passo 5: Commit**

```bash
git add service/app/user/ tests/
git commit -m "feat: implement user registration, login, token refresh"
```

---

### Tarefa 1.2: Catálogo de produtos (lista, detalhes, busca)

**Arquivos:**
- Criar: `service/app/product/controller/ProductController.php`
- Criar: `service/app/product/service/ProductService.php`
- Criar: `service/app/product/model/Product.php`
- Criar: `service/app/product/model/ProductSku.php`
- Criar: `service/app/product/model/Region.php`

- [ ] **Passo 1: Criar o model Product com relacionamentos**

```php
<?php
namespace App\Product\Model;

use Illuminate\Database\Eloquent\Model;
use Common\I18n\I18n;

class Product extends Model
{
    protected $casts = [
        'name'        => 'array',
        'description' => 'array',
    ];

    protected $appends = ['name_localized', 'description_localized'];

    public function getNameLocalizedAttribute(): ?string
    {
        return I18n::translateField($this->name);
    }

    public function getDescriptionLocalizedAttribute(): ?string
    {
        return I18n::translateField($this->description);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function skus()
    {
        return $this->hasMany(ProductSku::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort');
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class)->where('status', 'published');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeByCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }
}
```

- [ ] **Passo 2: Criar o ProductService**

```php
<?php
namespace App\Product\Service;

use App\Product\Model\Product;
use App\Product\Model\ProductSku;
use App\Product\Model\Region;
use Common\Helper\Response;

class ProductService
{
    public function list(array $filters, int $page = 1, int $pageSize = 20): array
    {
        $query = Product::published()->with(['category', 'skus.regionPrices']);

        if (!empty($filters['category_id'])) {
            $query->byCategory($filters['category_id']);
        }
        if (!empty($filters['region_id'])) {
            $query->whereHas('skus.regionPrices', function ($q) use ($filters) {
                $q->where('region_id', $filters['region_id']);
            });
        }
        if (!empty($filters['keyword'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereJsonContains('name', $filters['keyword'])
                  ->orWhere('slug', 'like', "%{$filters['keyword']}%");
            });
        }
        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        $total = $query->count();
        $items = $query->skip(($page - 1) * $pageSize)->take($pageSize)->get();

        return Response::paginated($items, $total, $page, $pageSize);
    }

    public function detail(int $id): Product
    {
        return Product::published()
            ->with(['category', 'skus.regionPrices', 'images', 'reviews.user.profile'])
            ->findOrFail($id);
    }

    public function getRegions(): array
    {
        return Region::where('status', 'active')->get()->groupBy('continent')->toArray();
    }
}
```

- [ ] **Passo 3: Criar o ProductController**

```php
<?php
namespace App\Product\Controller;

use App\Product\Service\ProductService;
use Common\Helper\Response;

class ProductController
{
    private ProductService $service;

    public function __construct()
    {
        $this->service = new ProductService();
    }

    public function index($request)
    {
        $filters  = $request->only(['category_id', 'region_id', 'keyword', 'supplier_id']);
        $page     = (int)$request->input('page', 1);
        $pageSize = min((int)$request->input('page_size', 20), 50);

        $result = $this->service->list($filters, $page, $pageSize);
        return json($result);
    }

    public function show($request, int $id)
    {
        $product = $this->service->detail($id);
        return json(Response::success($product));
    }

    public function regions()
    {
        $regions = $this->service->getRegions();
        return json(Response::success($regions));
    }
}
```

- [ ] **Passo 4: Commit**

```bash
git add service/app/product/
git commit -m "feat: implement product catalog list, detail, region endpoints"
```

---

### Tarefa 1.3: Carrinho de compras e criação de pedidos

**Arquivos:**
- Criar: `service/app/order/controller/CartController.php`
- Criar: `service/app/order/controller/OrderController.php`
- Criar: `service/app/order/service/CartService.php`
- Criar: `service/app/order/service/OrderService.php`
- Criar: `service/app/order/model/Order.php`
- Criar: `service/app/order/model/OrderItem.php`

- [ ] **Passo 1: Criar o model Order e o service de pedidos (lógica principal)**

```php
<?php
namespace App\Order\Service;

use App\Order\Model\Order;
use App\Order\Model\OrderItem;
use App\Order\Model\Cart;
use App\Product\Model\ProductSku;
use App\Product\Model\ProductRegion;
use Illuminate\Database\Capsule\Manager as DB;

class OrderService
{
    // Generate unique order number
    private function generateOrderNo(): string
    {
        return date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    // Add item to cart
    public function addToCart(int $userId, array $data): void
    {
        Cart::updateOrCreate(
            [
                'user_id'   => $userId,
                'sku_id'    => $data['sku_id'],
                'region_id' => $data['region_id'],
            ],
            [
                'quantity' => $data['quantity'] ?? 1,
                'cycle'    => $data['cycle'] ?? 'monthly',
            ]
        );
    }

    // Create order from cart items
    public function createFromCart(int $userId, array $cartIds, string $currency = 'USD'): Order
    {
        $carts = Cart::whereIn('id', $cartIds)->where('user_id', $userId)->with(['sku.product'])->get();

        if ($carts->isEmpty()) {
            throw new \InvalidArgumentException('Cart is empty');
        }

        return DB::transaction(function () use ($userId, $carts, $currency) {
            $subtotal = 0;
            $items = [];

            foreach ($carts as $cart) {
                $regionPrice = ProductRegion::where('sku_id', $cart->sku_id)
                    ->where('region_id', $cart->region_id)
                    ->where('currency', $currency)
                    ->firstOrFail();

                if ($regionPrice->stock < $cart->quantity) {
                    throw new \InvalidArgumentException("Insufficient stock for SKU {$cart->sku_id}");
                }

                $totalPrice = bcmul($regionPrice->price, $cart->quantity, 4);
                $subtotal   = bcadd($subtotal, $totalPrice, 4);

                $items[] = [
                    'sku_id'     => $cart->sku_id,
                    'region_id'  => $cart->region_id,
                    'product_id' => $cart->sku->product_id,
                    'quantity'   => $cart->quantity,
                    'cycle'      => $cart->cycle,
                    'unit_price' => $regionPrice->price,
                    'total_price'=> $totalPrice,
                    'resource_snapshot' => json_encode([
                        'specs' => $cart->sku->specs,
                        'region'=> $cart->region_id,
                    ]),
                ];
            }

            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'user_id'  => $userId,
                'type'     => 'new',
                'status'   => 'pending',
                'currency' => $currency,
                'subtotal' => $subtotal,
                'total'    => $subtotal, // discount + tax will be applied later
                'exchange_rate' => $this->getExchangeRate($currency),
            ]);

            foreach ($items as $item) {
                $item['order_id'] = $order->id;
                OrderItem::create($item);
            }

            // Add timeline entry
            OrderTimeline::create([
                'order_id' => $order->id,
                'status'   => 'pending',
                'operator' => 'system',
                'remark'   => 'Order created',
            ]);

            // Clear cart items
            Cart::whereIn('id', $cartIds)->delete();

            // Decrease stock
            foreach ($carts as $cart) {
                ProductRegion::where('sku_id', $cart->sku_id)
                    ->where('region_id', $cart->region_id)
                    ->decrement('stock', $cart->quantity);
            }

            return $order->load('items');
        });
    }

    // Get exchange rate for currency
    private function getExchangeRate(string $currency): string
    {
        if ($currency === 'USD') return '1.000000';
        $rate = Redis::get("exchange_rate:{$currency}");
        return $rate ?: '1.000000';
    }
}
```

- [ ] **Passo 2: Criar o OrderController**

```php
<?php
namespace App\Order\Controller;

use App\Order\Service\OrderService;
use App\Order\Service\CartService;
use App\Order\Model\Order;
use Common\Helper\Response;

class OrderController
{
    private OrderService $orderService;
    private CartService $cartService;

    public function __construct()
    {
        $this->orderService = new OrderService();
        $this->cartService  = new CartService();
    }

    // POST /api/cart — add to cart
    public function addToCart($request)
    {
        $data = $request->only(['sku_id', 'region_id', 'quantity', 'cycle']);
        $this->orderService->addToCart($request->userId, $data);
        return json(Response::success(null, 'Added to cart'));
    }

    // GET /api/cart — view cart
    public function cart($request)
    {
        $items = $this->cartService->getCart($request->userId);
        return json(Response::success($items));
    }

    // POST /api/orders — create order from cart
    public function store($request)
    {
        $cartIds  = $request->input('cart_ids', []);
        $currency = $request->input('currency', 'USD');

        try {
            $order = $this->orderService->createFromCart($request->userId, $cartIds, $currency);
            return json(Response::success($order, 'Order created'));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        }
    }

    // GET /api/user/orders — my orders
    public function myOrders($request)
    {
        $page     = (int)$request->input('page', 1);
        $pageSize = (int)$request->input('page_size', 10);

        $orders = Order::where('user_id', $request->userId)
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);

        return json(Response::paginated(
            $orders->items(),
            $orders->total(),
            $page,
            $pageSize
        ));
    }

    // GET /api/orders/{id} — order detail
    public function show($request, int $id)
    {
        $order = Order::with(['items', 'timeline'])
            ->where('user_id', $request->userId)
            ->findOrFail($id);

        return json(Response::success($order));
    }
}
```

- [ ] **Passo 3: Commit**

```bash
git add service/app/order/
git commit -m "feat: implement shopping cart and order creation"
```

---

### Tarefa 1.4: Integração de pagamento (Stripe + Router)

**Arquivos:**
- Criar: `service/app/payment/controller/PaymentController.php`
- Criar: `service/app/payment/service/PaymentService.php`
- Criar: `service/app/payment/service/PaymentRouter.php`
- Criar: `service/app/payment/service/Channels/StripeChannel.php`
- Criar: `service/app/payment/service/Channels/CryptoChannel.php`
- Criar: `service/app/payment/model/PaymentChannel.php`
- Criar: `service/app/payment/model/PaymentTransaction.php`
- Criar: `service/app/payment/event/OrderPaid.php`

- [ ] **Passo 1: Criar o PaymentRouter**

```php
<?php
namespace App\Payment\Service;

use App\Payment\Model\PaymentChannel;

class PaymentRouter
{
    public function getAvailableChannels(array $context): array
    {
        $channels = PaymentChannel::where('status', 'active')
            ->where('is_visible', true)
            ->get();

        $result = [];
        foreach ($channels as $channel) {
            // Filter by visible regions
            if ($channel->visible_regions && !in_array($context['region'] ?? 'global', $channel->visible_regions)) {
                continue;
            }
            // Filter by amount range
            if ($channel->min_amount && $context['amount'] < $channel->min_amount) continue;
            if ($channel->max_amount && $context['amount'] > $channel->max_amount) continue;
            // Filter by currency support
            if (!in_array($context['currency'], $channel->currency_support)) continue;

            $feeConfig = json_decode($channel->fee_config, true);
            $fee = $this->calculateFee($context['amount'], $feeConfig);

            $result[] = [
                'channel_id'   => $channel->id,
                'name'         => $channel->name,
                'code'         => $channel->code,
                'amount'       => $context['amount'],
                'fee'          => $fee,
                'total_amount' => bcadd($context['amount'], $fee, 4),
            ];
        }

        return $result;
    }

    private function calculateFee(string $amount, array $feeConfig): string
    {
        $fixed = $feeConfig['fixed'] ?? '0';
        $rate  = $feeConfig['rate'] ?? '0';
        $fee   = bcadd(bcmul($amount, $rate, 8), $fixed, 4);
        return max($fee, '0');
    }
}
```

- [ ] **Passo 2: Criar o StripeChannel**

```php
<?php
namespace App\Payment\Service\Channels;

use App\Payment\Model\PaymentTransaction;
use App\Payment\Model\PaymentChannel;
use App\Order\Model\Order;
use Stripe\StripeClient;

class StripeChannel
{
    private PaymentChannel $channel;
    private StripeClient $stripe;

    public function __construct(PaymentChannel $channel)
    {
        $this->channel = $channel;
        $this->stripe  = new StripeClient($channel->api_key_encrypted);
    }

    public function createPaymentIntent(Order $order): array
    {
        $intent = $this->stripe->paymentIntents->create([
            'amount'   => (int)bcmul($order->total, '100', 0), // cents
            'currency' => strtolower($order->currency),
            'metadata' => [
                'order_id'  => $order->id,
                'order_no'  => $order->order_no,
            ],
            'description' => "Order #{$order->order_no}",
        ]);

        // Create transaction record
        PaymentTransaction::create([
            'order_id'       => $order->id,
            'user_id'        => $order->user_id,
            'channel_id'     => $this->channel->id,
            'amount'         => $order->total,
            'currency'       => $order->currency,
            'exchange_rate'  => $order->exchange_rate,
            'channel_fee'    => '0',
            'transaction_no' => $intent->id,
            'status'         => 'pending',
        ]);

        return [
            'client_secret' => $intent->client_secret,
            'transaction_id'=> $intent->id,
        ];
    }

    public function handleWebhook(string $payload, string $signature): void
    {
        $event = $this->stripe->webhooks->constructEvent(
            $payload,
            $signature,
            $this->channel->webhook_secret
        );

        if ($event->type === 'payment_intent.succeeded') {
            $intent = $event->data->object;
            $this->confirmPayment($intent->id, $intent->metadata->order_id);
        }
    }

    public function confirmPayment(string $transactionNo, int $orderId): void
    {
        $txn = PaymentTransaction::where('transaction_no', $transactionNo)
            ->where('order_id', $orderId)
            ->where('status', 'pending')
            ->firstOrFail();

        $txn->update(['status' => 'success', 'callback_at' => now()]);

        // Update order status
        $order = Order::findOrFail($orderId);
        $order->update(['status' => 'paid', 'paid_at' => now()]);

        // Add timeline
        OrderTimeline::create([
            'order_id' => $orderId,
            'status'   => 'paid',
            'operator' => 'payment',
            'remark'   => 'Payment confirmed via Stripe',
        ]);

        // Fire event — triggers provisioning
        event(new OrderPaid($order));
    }
}
```

- [ ] **Passo 3: Criar o evento OrderPaid e o PaymentController**

```php
<?php
namespace App\Payment\Event;

use App\Order\Model\Order;

class OrderPaid
{
    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }
}
```

```php
<?php
namespace App\Payment\Controller;

use App\Payment\Service\PaymentRouter;
use App\Payment\Service\Channels\StripeChannel;
use App\Payment\Model\PaymentChannel;
use App\Order\Model\Order;
use Common\Helper\Response;

class PaymentController
{
    // GET /api/orders/{id}/payment-methods
    public function availableChannels($request, int $orderId)
    {
        $order = Order::where('user_id', $request->userId)->findOrFail($orderId);
        if ($order->status !== 'pending') {
            return json(Response::error(422, 'Order cannot be paid'));
        }

        $router = new PaymentRouter();
        $channels = $router->getAvailableChannels([
            'amount'   => $order->total,
            'currency' => $order->currency,
            'region'   => 'global',
        ]);

        return json(Response::success($channels));
    }

    // POST /api/orders/{id}/pay
    public function pay($request, int $orderId)
    {
        $order     = Order::where('user_id', $request->userId)->findOrFail($orderId);
        $channelId = $request->input('channel_id');

        $channel = PaymentChannel::findOrFail($channelId);

        if ($channel->code === 'stripe') {
            $stripeChannel = new StripeChannel($channel);
            $result = $stripeChannel->createPaymentIntent($order);
            return json(Response::success($result));
        }

        // Other channel implementations follow same pattern

        return json(Response::error(422, 'Unsupported payment channel'));
    }

    // POST /api/payments/webhook/stripe
    public function stripeWebhook($request)
    {
        $payload   = $request->rawBody();
        $signature = $request->header('Stripe-Signature');

        $channel = PaymentChannel::where('code', 'stripe')->firstOrFail();
        $stripeChannel = new StripeChannel($channel);
        $stripeChannel->handleWebhook($payload, $signature);

        return json(Response::success());
    }
}
```

- [ ] **Passo 4: Commit**

```bash
git add service/app/payment/
git commit -m "feat: implement payment system with Stripe integration"
```

---

### Tarefa 1.5: Registrar as rotas e testar de ponta a ponta

- [ ] **Passo 1: Atualizar a configuração de rotas** — `service/config/route.php`

Adicionar rotas:
```php
// Product routes (public)
Route::get('/api/products', [\App\Product\Controller\ProductController::class, 'index']);
Route::get('/api/products/{id}', [\App\Product\Controller\ProductController::class, 'show']);
Route::get('/api/regions', [\App\Product\Controller\ProductController::class, 'regions']);

// Cart routes (auth required)
Route::group('/api', function () {
    Route::post('/cart', [\App\Order\Controller\OrderController::class, 'addToCart']);
    Route::get('/cart', [\App\Order\Controller\OrderController::class, 'cart']);
    Route::post('/orders', [\App\Order\Controller\OrderController::class, 'store']);
    Route::get('/orders', [\App\Order\Controller\OrderController::class, 'myOrders']);
    Route::get('/orders/{id}', [\App\Order\Controller\OrderController::class, 'show']);
    Route::get('/orders/{id}/payment-methods', [\App\Payment\Controller\PaymentController::class, 'availableChannels']);
    Route::post('/orders/{id}/pay', [\App\Payment\Controller\PaymentController::class, 'pay']);
})->middleware([\Common\Auth\Middleware\AuthMiddleware::class]);

// Payment webhook (no auth, signature verification instead)
Route::post('/api/payments/webhook/stripe', [\App\Payment\Controller\PaymentController::class, 'stripeWebhook']);
```

- [ ] **Passo 2: Script manual de teste ponta a ponta**

```bash
# Register a user
curl -X POST http://localhost:8787/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Test1234","country":"US"}'

# Login
curl -X POST http://localhost:8787/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"login":"test@example.com","password":"Test1234"}'

# Browse products (use token from login)
curl http://localhost:8787/api/products

# Add to cart
curl -X POST http://localhost:8787/api/cart \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"sku_id":1,"region_id":1,"quantity":1,"cycle":"monthly"}'

# Create order
curl -X POST http://localhost:8787/api/orders \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"cart_ids":[1]}'

# Get payment methods
curl http://localhost:8787/api/orders/1/payment-methods \
  -H "Authorization: Bearer <access_token>"
```

- [ ] **Passo 3: Commit**

```bash
git add service/config/route.php
git commit -m "feat: wire all Phase 1 routes, add E2E test script"
```

---

**Fase 1 concluída.** Os usuários agora podem: registrar → fazer login → navegar por produtos → adicionar ao carrinho → criar pedido → ver formas de pagamento → pagar via Stripe. O evento OrderPaid é disparado e está pronto para a integração de provisionamento da Fase 2.
