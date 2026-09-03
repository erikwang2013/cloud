import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'client_platform.dart';

class ApiClient {
  late final Dio dio;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  // API version lives in the URL path (/api/v1/...)
  static const String baseUrl = 'https://api.example.com/api/v1';

  ApiClient() {
    dio = Dio(BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 10),
      headers: {
        'Content-Type': 'application/json',
        'X-Client-Platform': getClientPlatform(),
      },
    ));

    dio.interceptors.add(AuthInterceptor(_storage, dio));
    dio.interceptors.add(LocaleInterceptor());
    dio.interceptors.add(LogInterceptor(requestBody: true, responseBody: true));
  }
}

class AuthInterceptor extends Interceptor {
  final FlutterSecureStorage _storage;
  final Dio _dio;

  AuthInterceptor(this._storage, this._dio);

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    final token = await _storage.read(key: 'access_token');
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    if (err.response?.statusCode == 401) {
      final refreshed = await _tryRefresh();
      if (refreshed) {
        final response = await _dio.fetch(err.requestOptions);
        handler.resolve(response);
        return;
      }
      await _storage.deleteAll();
    }
    handler.next(err);
  }

  Future<bool> _tryRefresh() async {
    final refreshToken = await _storage.read(key: 'refresh_token');
    if (refreshToken == null) return false;

    try {
      final response = await Dio().post('${ApiClient.baseUrl}/auth/refresh', data: {
        'refresh_token': refreshToken,
      }, options: Options(headers: {'X-Client-Platform': getClientPlatform()}));
      final respData = response.data['data'];
      if (respData != null) {
        await _storage.write(key: 'access_token', value: respData['access_token']);
        await _storage.write(key: 'refresh_token', value: respData['refresh_token']);
      }
      return true;
    } catch (_) {
      return false;
    }
  }
}

class LocaleInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers['Accept-Language'] = 'zh-CN';
    handler.next(options);
  }
}
