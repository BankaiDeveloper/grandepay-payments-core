import crypto from 'k6/crypto';
import exec from 'k6/execution';
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

const manifest = JSON.parse(open(__ENV.MANIFEST_PATH || '../../storage/app/benchmarks/postback-manifest.json'));
const baseUrl = (__ENV.APP_BASE_URL || manifest.app_base_url || 'http://127.0.0.1:9501').replace(/\/$/, '');
const endpoint = __ENV.WEBHOOK_ENDPOINT || manifest.webhook_endpoint || '/api/webhooks/firebank';
const webhookSecret = __ENV.WEBHOOK_SECRET || manifest.provider.webhook_secret;
const duplicateRatio = Number(__ENV.DUPLICATE_RATIO || '0');
const seededTransactions = manifest.transactions || [];

if (seededTransactions.length === 0) {
    throw new Error('Manifest has no transactions. Run: php artisan benchmark:seed-postback');
}

const webhookFailures = new Counter('webhook_failures');
const webhookDuration = new Trend('webhook_duration_ms');
const webhookSuccessRate = new Rate('webhook_success_rate');

export const options = {
    scenarios: {
        hypervel_postback_benchmark: {
            executor: 'constant-arrival-rate',
            rate: Number(__ENV.RATE || '100'),
            timeUnit: '1s',
            duration: __ENV.DURATION || '30s',
            preAllocatedVUs: Number(__ENV.PRE_ALLOCATED_VUS || '50'),
            maxVUs: Number(__ENV.MAX_VUS || '500'),
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.05'],
        http_req_duration: ['p(95)<500'],
        webhook_success_rate: ['rate>0.95'],
    },
};

function pickTransaction() {
    if (Math.random() < duplicateRatio) {
        return seededTransactions[Math.floor(Math.random() * seededTransactions.length)];
    }
    const index = exec.scenario.iterationInTest % seededTransactions.length;
    return seededTransactions[index];
}

export default function () {
    const item = pickTransaction();
    const payload = {
        event: 'CashIn',
        status: 'CONFIRMED',
        externalId: item.external_id,
        transactionId: item.provider_transaction_id,
        feeAmount: item.fee_amount,
        finalAmount: item.final_amount,
        endToEndId: item.end_to_end_id,
        counterpart: item.counterpart,
    };

    const body = JSON.stringify(payload);
    const signature = crypto.hmac('sha256', webhookSecret, body, 'hex');

    const response = http.post(`${baseUrl}${endpoint}`, body, {
        headers: {
            'Content-Type': 'application/json',
            'X-Signature': signature,
        },
        timeout: __ENV.HTTP_TIMEOUT || '10s',
        tags: { scenario: 'hypervel_postback_benchmark' },
    });

    webhookDuration.add(response.timings.duration);

    const success = check(response, {
        'status is 200': (res) => res.status === 200,
        'acknowledged': (res) => {
            try { return JSON.parse(res.body).acknowledged === true; }
            catch (_) { return false; }
        },
    });

    webhookSuccessRate.add(success);
    if (!success) {
        webhookFailures.add(1, { status: String(response.status) });
    }
}
