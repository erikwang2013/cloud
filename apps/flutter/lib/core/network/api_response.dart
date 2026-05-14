class ApiResponse {
  final int code;
  final String message;
  final dynamic data;
  final Map<String, dynamic>? meta;

  ApiResponse({
    required this.code,
    required this.message,
    this.data,
    this.meta,
  });

  factory ApiResponse.fromJson(Map<String, dynamic> json) {
    return ApiResponse(
      code: json['code'] ?? 0,
      message: json['message'] ?? '',
      data: json['data'],
      meta: json['meta'],
    );
  }

  bool get isSuccess => code == 0 || code == 200;
}
