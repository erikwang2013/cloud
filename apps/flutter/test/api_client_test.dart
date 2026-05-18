import 'package:flutter_test/flutter_test.dart';
import 'package:cloud_platform/core/network/api_response.dart';

void main() {
  group('ApiResponse', () {
    test('parses success response', () {
      final json = {
        'code': 0,
        'message': 'Success',
        'data': {'id': 1, 'name': 'test'},
      };
      final response = ApiResponse.fromJson(json);
      expect(response.isSuccess, isTrue);
      expect(response.code, 0);
      expect(response.message, 'Success');
      expect(response.data, isNotNull);
    });

    test('parses 200 as success', () {
      final json = {
        'code': 200,
        'message': 'OK',
      };
      final response = ApiResponse.fromJson(json);
      expect(response.isSuccess, isTrue);
    });

    test('parses error response', () {
      final json = {
        'code': 401,
        'message': 'Unauthorized',
      };
      final response = ApiResponse.fromJson(json);
      expect(response.isSuccess, isFalse);
    });

    test('parses response with meta', () {
      final json = {
        'code': 0,
        'message': 'Success',
        'data': [],
        'meta': {'page': 1, 'total': 100},
      };
      final response = ApiResponse.fromJson(json);
      expect(response.meta, isNotNull);
      expect(response.meta!['page'], 1);
      expect(response.meta!['total'], 100);
    });

    test('handles missing code field', () {
      final json = <String, dynamic>{'message': 'No code field'};
      final response = ApiResponse.fromJson(json);
      expect(response.code, 0);
      expect(response.isSuccess, isTrue);
    });

    test('handles missing message field', () {
      final json = <String, dynamic>{'code': 500};
      final response = ApiResponse.fromJson(json);
      expect(response.message, '');
    });
  });
}
