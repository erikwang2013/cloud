import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'api_client.dart';
import 'client_platform.dart';

/// Shared API data service used by all feature pages.
class ApiService {
  final Dio _dio;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  ApiService()
      : _dio = Dio(BaseOptions(
          baseUrl: ApiClient.baseUrl,
          connectTimeout: const Duration(seconds: 10),
          headers: {
            'Content-Type': 'application/json',
            'X-Api-Version': ApiClient.apiVersion,
            'X-Client-Platform': getClientPlatform(),
          },
        )) {
    _dio.interceptors.add(_AuthInterceptor(_storage));
  }

  // ── Products ──
  Future<List<dynamic>> getProducts({int page = 1, String? categoryId, String? regionId, String? keyword}) async {
    final params = <String, dynamic>{'page': page};
    if (categoryId != null) params['category_id'] = categoryId;
    if (regionId != null) params['region_id'] = regionId;
    if (keyword != null) params['keyword'] = keyword;
    final resp = await _dio.get('/products', queryParameters: params);
    return resp.data['data'] ?? [];
  }

  Future<Map<String, dynamic>> getProductDetail(int id) async {
    final resp = await _dio.get('/products/$id');
    return resp.data['data'] ?? {};
  }

  Future<List<dynamic>> searchProducts(String query, {int page = 1}) async {
    final resp = await _dio.get('/products/search', queryParameters: {'q': query, 'page': page});
    return resp.data['data'] ?? [];
  }

  // ── Cart ──
  Future<Map<String, dynamic>> getCart() async {
    final resp = await _dio.get('/cart');
    return resp.data['data'] ?? {};
  }

  Future<void> addToCart(int skuId, int regionId, int quantity, String cycle) async {
    await _dio.post('/cart', data: {'sku_id': skuId, 'region_id': regionId, 'quantity': quantity, 'cycle': cycle});
  }

  Future<void> removeFromCart(int id) async {
    await _dio.delete('/cart/$id');
  }

  Future<void> updateCartQuantity(int id, int quantity) async {
    await _dio.put('/cart/$id', data: {'quantity': quantity});
  }

  // ── Orders ──
  Future<Map<String, dynamic>> createOrder() async {
    final resp = await _dio.post('/orders');
    return resp.data['data'] ?? {};
  }

  Future<List<dynamic>> getOrders({int page = 1}) async {
    final resp = await _dio.get('/orders', queryParameters: {'page': page});
    return resp.data['data'] ?? [];
  }

  // ── Resources ──
  Future<List<dynamic>> getResources({int page = 1, String? status}) async {
    final params = <String, dynamic>{'page': page};
    if (status != null) params['status'] = status;
    final resp = await _dio.get('/resources', queryParameters: params);
    return resp.data['data'] ?? [];
  }

  // ── Auth ──
  Future<Map<String, dynamic>> login(String email, String password) async {
    final resp = await _dio.post('/auth/login', data: {'login': email, 'password': password});
    final data = resp.data['data'] ?? {};
    if (data['access_token'] != null) {
      await _storage.write(key: 'access_token', value: data['access_token']);
      await _storage.write(key: 'refresh_token', value: data['refresh_token'] ?? '');
    }
    return data;
  }

  Future<Map<String, dynamic>> getProfile() async {
    final resp = await _dio.get('/user/profile');
    return resp.data['data'] ?? {};
  }
}

class _AuthInterceptor extends Interceptor {
  final FlutterSecureStorage _storage;
  _AuthInterceptor(this._storage);

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    final token = await _storage.read(key: 'access_token');
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }
}
