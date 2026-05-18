import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cloud_platform/features/auth/pages/login_page.dart';
import 'package:cloud_platform/features/auth/pages/register_page.dart';
import 'package:cloud_platform/features/products/pages/product_list_page.dart';
import 'package:cloud_platform/features/orders/pages/cart_page.dart';
import 'package:cloud_platform/features/resources/pages/resource_list_page.dart';

void main() {
  testWidgets('LoginPage renders', (WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: LoginPage()));
    expect(find.text('Sign in to CloudPlatform'), findsOneWidget);
    expect(find.text('Email'), findsWidgets);
    expect(find.text('Password'), findsWidgets);
  });

  testWidgets('LoginPage validates empty email', (WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: LoginPage()));
    final signInButton = find.text('Sign In');
    await tester.tap(signInButton);
    await tester.pumpAndSettle();
    expect(find.text('Email is required'), findsOneWidget);
  });

  testWidgets('RegisterPage renders', (WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: RegisterPage()));
    expect(find.text('Create your account'), findsOneWidget);
    expect(find.text('Full Name'), findsWidgets);
    expect(find.text('Email'), findsWidgets);
  });

  testWidgets('RegisterPage validates password match', (WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: RegisterPage()));

    // Fill in password
    await tester.enterText(find.byType(TextFormField).at(2), 'password123');
    // Fill in different confirm password
    await tester.enterText(find.byType(TextFormField).at(3), 'different');
    // Check agree
    await tester.tap(find.byType(Checkbox));
    await tester.pumpAndSettle();
    // Submit - scroll down first to ensure button is visible
    await tester.ensureVisible(find.text('Create Account'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Create Account'));
    await tester.pumpAndSettle();

    expect(find.text('Passwords do not match'), findsOneWidget);
  });

  testWidgets('ProductListPage renders', (WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: ProductListPage())));
    await tester.pumpAndSettle();
    expect(find.text('Products'), findsOneWidget);
  });

  testWidgets('CartPage renders', (WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: CartPage())));
    await tester.pumpAndSettle();
    expect(find.text('Shopping Cart'), findsOneWidget);
  });

  testWidgets('ResourceListPage renders', (WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: ResourceListPage())));
    await tester.pumpAndSettle();
    expect(find.text('My Resources'), findsOneWidget);
  });
}
