import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';

const tenants = new SharedArray('staging tenant tokens', () => {
    const records = JSON.parse(__ENV.TENANTS_FILE ? open(__ENV.TENANTS_FILE) : '[]');

    if (!Array.isArray(records) || records.length === 0) {
        throw new Error('TENANTS_FILE must contain staging tenant API keys and Sanctum tokens.');
    }

    if (records.some((tenant) => !tenant.api_key || !tenant.access_token)) {
        throw new Error('Each staging tenant record must include an API key and a Sanctum token.');
    }

    return records;
});

const hubBaseUrl = (__ENV.HUB_BASE_URL || '').replace(/\/$/, '');

if (!hubBaseUrl) {
    throw new Error('HUB_BASE_URL must point to the staging hub.');
}

const startRate = Number(__ENV.LOAD_START_RATE || 5);
const targetRate = Number(__ENV.LOAD_TARGET_RATE || 50);
const rampDuration = __ENV.LOAD_RAMP_DURATION || '5m';
const peakDuration = __ENV.LOAD_PEAK_DURATION || '10m';

export const options = {
    scenarios: {
        tenant_api: {
            executor: 'ramping-arrival-rate',
            startRate,
            timeUnit: '1s',
            preAllocatedVUs: Number(__ENV.LOAD_PREALLOCATED_VUS || 20),
            maxVUs: Number(__ENV.LOAD_MAX_VUS || 200),
            stages: [
                { duration: rampDuration, target: targetRate },
                { duration: peakDuration, target: targetRate },
                { duration: rampDuration, target: 0 },
            ],
        },
    },
};

export default function () {
    const tenant = tenants[(__VU - 1) % tenants.length];
    const response = http.get(
        `${hubBaseUrl}/api/v1/tenant/protected-resource`,
        {
            headers: {
                Authorization: `Bearer ${tenant.access_token}`,
                'X-Tenant-Key': tenant.api_key,
                Accept: 'application/json',
            },
        },
    );

    check(response, {
        'tenant endpoint returns 200': (result) => result.status === 200,
    });
}
