#!/bin/sh
# Run with: docker compose exec app sh /var/www/html/opay_auth_test.sh

MERCHANT_ID=$(grep OPAY_MERCHANT_ID /var/www/html/.env | cut -d '=' -f2 | tr -d '\r')
SECRET_KEY=$(grep OPAY_SECRET_KEY /var/www/html/.env | cut -d '=' -f2 | tr -d '\r')
BASE_URL=$(grep OPAY_BASE_URL /var/www/html/.env | cut -d '=' -f2 | tr -d '\r')

echo "Merchant ID : $MERCHANT_ID"
echo "Secret key  : $(echo $SECRET_KEY | cut -c1-12)... ($(echo -n $SECRET_KEY | wc -c) chars)"
echo "Base URL    : $BASE_URL"
echo ""

# Build payload
PAYLOAD='{"country":"NG","reference":"TEST-'$(date +%s)'","amount":{"total":1000,"currency":"NGN"},"returnUrl":"https://example.com","callbackUrl":"https://example.com","cancelUrl":"https://example.com","expireAt":30,"userInfo":{"userName":"Test User","userEmail":"test@test.com"},"product":{"name":"Test","description":"Test deposit"}}'

echo "Payload: $PAYLOAD"
echo ""

# Sign it
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha512 -hmac "$SECRET_KEY" | sed 's/^.* //')
echo "Signature (first 20 chars): $(echo $SIGNATURE | cut -c1-20)..."
echo ""

echo "Sending request..."
curl -s -w "\n\nHTTP Status: %{http_code}\n" \
  -X POST "$BASE_URL/api/v1/international/cashier/create" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $SIGNATURE" \
  -H "MerchantId: $MERCHANT_ID" \
  -d "$PAYLOAD"
