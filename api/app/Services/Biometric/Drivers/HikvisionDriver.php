<?php

namespace App\Services\Biometric\Drivers;

use App\Enums\AttendanceDirection;
use App\Enums\VerificationType;
use App\Models\BiometricDevice;
use App\Services\Biometric\Contracts\DeviceDriver;
use App\Services\Biometric\DTO\DeviceEvent;
use App\Services\Biometric\DTO\DeviceInfo;
use App\Services\Biometric\DTO\DeviceUser;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hikvision ISAPI driver (SRS 7).
 *
 * Speaks the ISAPI HTTP interface with digest authentication, which is what
 * Hikvision access-control terminals expose out of the box - no vendor SDK,
 * no native library, so it deploys on ordinary PHP hosting.
 *
 * Two ways in, both supported:
 *  - PULL: page through /ISAPI/AccessControl/AcsEvent on a timer (SRS 13).
 *  - PUSH: the device posts events to our listener endpoint as they happen.
 *
 * PULL is the one that must always work. Push is faster but silently stops
 * being delivered if the device loses our address, whereas a poll that finds
 * nothing is visibly a poll that found nothing.
 */
class HikvisionDriver implements DeviceDriver
{
    /** Hikvision groups access-control events under major type 5. */
    private const MAJOR_ACCESS_CONTROL = 5;

    public function __construct(private readonly BiometricDevice $device) {}

    public function testConnection(): DeviceInfo
    {
        try {
            // No retries here: an administrator pressing "Test connection"
            // is waiting on the answer, and three attempts at an unreachable
            // address just makes them wait three times as long to hear it.
            $response = $this->client(retries: 1)->get('/ISAPI/System/deviceInfo');

            if ($response->status() === 401) {
                return DeviceInfo::failed('Authentication failed: check the device username and password.');
            }

            if (! $response->successful()) {
                return DeviceInfo::failed("Device returned HTTP {$response->status()}.");
            }

            $info = $this->parseXml($response->body());

            return new DeviceInfo(
                reachable: true,
                model: $info['model'] ?? null,
                serialNumber: $info['serialNumber'] ?? null,
                firmware: trim(($info['firmwareVersion'] ?? '').' '.($info['firmwareReleasedDate'] ?? '')) ?: null,
                deviceName: $info['deviceName'] ?? null,
                raw: $info,
            );
        } catch (\Throwable $e) {
            return DeviceInfo::failed($this->friendlyError($e));
        }
    }

    /**
     * SRS 13 - Page through the device's access-control event log.
     *
     * @return \Generator<DeviceEvent>
     */
    public function fetchEvents(CarbonInterface $from, CarbonInterface $to): iterable
    {
        // One searchID must span every page of a single search. Restarting it
        // per page makes the device begin again from the top, so a busy
        // reader would return page one forever.
        $searchId = (string) Str::uuid();
        $position = 0;
        $pageSize = (int) config('gms.biometric.page_size', 100);
        $maxPages = (int) config('gms.biometric.max_pages', 50);

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->send(fn () => $this->client()->post('/ISAPI/AccessControl/AcsEvent?format=json', [
                'AcsEventCond' => [
                    'searchID' => $searchId,
                    'searchResultPosition' => $position,
                    'maxResults' => $pageSize,
                    'major' => self::MAJOR_ACCESS_CONTROL,
                    'minor' => 0, // 0 = every minor type under the major
                    'startTime' => $this->formatTime($from),
                    'endTime' => $this->formatTime($to),
                ],
            ]));

            $this->assertOk($response, 'fetch attendance events');

            $body = $response->json('AcsEvent') ?? [];
            $items = $body['InfoList'] ?? [];

            foreach ($items as $item) {
                $event = $this->mapEvent($item);

                if ($event !== null) {
                    yield $event;
                }
            }

            $returned = (int) ($body['numOfMatches'] ?? count($items));
            $status = strtoupper((string) ($body['responseStatusStrg'] ?? 'OK'));

            // "MORE" is the only status that means another page exists; "OK"
            // is the last page and "NO MATCH" is an empty result.
            if ($status !== 'MORE' || $returned === 0) {
                return;
            }

            $position += $returned;
        }

        Log::warning('Hikvision event pull hit the page ceiling; remaining events will be picked up next run.', [
            'device_id' => $this->device->id,
            'max_pages' => $maxPages,
        ]);
    }

    /**
     * SRS 24 - Download the users on the device.
     *
     * @return \Generator<DeviceUser>
     */
    public function fetchUsers(): iterable
    {
        $searchId = (string) Str::uuid();
        $position = 0;
        $pageSize = (int) config('gms.biometric.page_size', 100);
        $maxPages = (int) config('gms.biometric.max_pages', 50);

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->send(fn () => $this->client()->post('/ISAPI/AccessControl/UserInfo/Search?format=json', [
                'UserInfoSearchCond' => [
                    'searchID' => $searchId,
                    'searchResultPosition' => $position,
                    'maxResults' => $pageSize,
                ],
            ]));

            $this->assertOk($response, 'fetch device users');

            $body = $response->json('UserInfoSearch') ?? [];
            $items = $body['UserInfo'] ?? [];

            foreach ($items as $item) {
                $user = $this->mapDeviceUser($item);

                if ($user !== null) {
                    yield $user;
                }
            }

            $returned = (int) ($body['numOfMatches'] ?? count($items));
            $status = strtoupper((string) ($body['responseStatusStrg'] ?? 'OK'));

            if ($status !== 'MORE' || $returned === 0) {
                return;
            }

            $position += $returned;
        }
    }

    /**
     * SRS 24 - Push a member onto the device as a user.
     *
     * Tries a create first and falls back to a modify, because the device
     * rejects a create for an employeeNo it already knows. Doing it in this
     * order keeps the common case - a brand new member - to one request.
     */
    public function upsertUser(DeviceUser $user): bool
    {
        $payload = [
            'UserInfo' => [
                'employeeNo' => $user->biometricUserId,
                'name' => $user->name ?? $user->biometricUserId,
                'userType' => 'normal',
                'Valid' => [
                    // Off for a member whose membership has lapsed or been
                    // frozen: the door stops opening for them, their
                    // fingerprints stay on the reader, and a renewal is one
                    // flag back rather than a fresh enrolment.
                    'enable' => $user->enabled,
                    'beginTime' => $user->validFrom ?? now()->format('Y-m-d\TH:i:s'),
                    // A far horizon rather than the member's own expiry date.
                    // Expiry is carried by the enable flag above, which we can
                    // set the moment a payment lands; an endTime would need
                    // rewriting on every renewal and would silently lock
                    // somebody out if a sync were ever missed.
                    'endTime' => $user->validTo ?? now()->addYears(10)->format('Y-m-d\TH:i:s'),
                    'timeType' => 'local',
                ],
                'doorRight' => '1',
                'RightPlan' => [
                    ['doorNo' => 1, 'planTemplateNo' => '1'],
                ],
            ],
        ];

        $create = $this->send(fn () => $this->client()->post('/ISAPI/AccessControl/UserInfo/Record?format=json', $payload));

        if ($this->isSuccessPayload($create)) {
            return true;
        }

        $modify = $this->send(fn () => $this->client()->put('/ISAPI/AccessControl/UserInfo/Modify?format=json', $payload));

        if ($this->isSuccessPayload($modify)) {
            return true;
        }

        throw new \RuntimeException(sprintf(
            'Device rejected user %s: %s',
            $user->biometricUserId,
            $this->errorMessage($modify) ?? $this->errorMessage($create) ?? 'unknown error',
        ));
    }

    /**
     * One UserInfo record from the device as our DTO.
     *
     * Null for a record with no usable employeeNo: a device that returns a
     * blank one has nothing we can match a scan against, and a DeviceUser with
     * an empty id would quietly collide with the next one.
     *
     * @param  array<mixed>  $item
     */
    private function mapDeviceUser(array $item): ?DeviceUser
    {
        $id = trim((string) ($item['employeeNo'] ?? $item['employeeNoString'] ?? ''));

        if ($id === '') {
            return null;
        }

        return new DeviceUser(
            biometricUserId: $id,
            name: $item['name'] ?? null,
            fingerprintCount: (int) ($item['numOfFP'] ?? $item['fingerPrintNum'] ?? 0),
            hasCard: (int) ($item['numOfCard'] ?? 0) > 0,
            hasFace: (int) ($item['numOfFace'] ?? 0) > 0,
            validFrom: $item['Valid']['beginTime'] ?? null,
            validTo: $item['Valid']['endTime'] ?? null,
            // Absent means the device does not model it; treat that as
            // enabled rather than locking everybody out.
            enabled: (bool) ($item['Valid']['enable'] ?? true),
            raw: $item,
        );
    }

    /**
     * Look one user up by employeeNo.
     *
     * The device accepts an EmployeeNoList filter, but not every firmware
     * honours it - some answer with the whole list regardless. So the filtered
     * result is checked for an exact match and, failing that, the full list is
     * walked. Costly only in the case where the fast path was never going to
     * work.
     */
    public function findUser(string $biometricUserId): ?DeviceUser
    {
        $response = $this->send(fn () => $this->client()->post('/ISAPI/AccessControl/UserInfo/Search?format=json', [
            'UserInfoSearchCond' => [
                'searchID' => (string) Str::uuid(),
                'searchResultPosition' => 0,
                'maxResults' => 30,
                'EmployeeNoList' => [
                    ['employeeNo' => $biometricUserId],
                ],
            ],
        ]));

        if ($response->successful()) {
            foreach ($response->json('UserInfoSearch.UserInfo') ?? [] as $item) {
                $found = $this->mapDeviceUser($item);

                if ($found !== null && $found->biometricUserId === $biometricUserId) {
                    return $found;
                }
            }
        }

        foreach ($this->fetchUsers() as $user) {
            if ($user->biometricUserId === $biometricUserId) {
                return $user;
            }
        }

        return null;
    }

    /**
     * SRS 7 - Release the door lock now.
     *
     * JSON first, because the rest of this driver speaks it and newer firmware
     * prefers it. Older terminals only accept the XML body, and a door that
     * will not open is not the place to be fussy about content types.
     */
    public function openDoor(int $doorNumber = 1): bool
    {
        $path = "/ISAPI/AccessControl/RemoteControl/door/{$doorNumber}";

        $json = $this->send(fn () => $this->client()->put($path.'?format=json', [
            'RemoteControlDoor' => ['cmd' => 'open'],
        ]));

        if ($this->isSuccessPayload($json)) {
            return true;
        }

        $xml = $this->send(fn () => $this->client()
            ->withBody('<RemoteControlDoor><cmd>open</cmd></RemoteControlDoor>', 'application/xml')
            ->put($path));

        if ($xml->successful()) {
            return true;
        }

        throw new \RuntimeException(sprintf(
            'Device refused to open door %d: %s',
            $doorNumber,
            $this->errorMessage($json) ?? "HTTP {$xml->status()}",
        ));
    }

    public function deleteUser(string $biometricUserId): bool
    {
        $response = $this->send(fn () => $this->client()->put('/ISAPI/AccessControl/UserInfo/Delete?format=json', [
            'UserInfoDelCond' => [
                'EmployeeNoList' => [
                    ['employeeNo' => $biometricUserId],
                ],
            ],
        ]));

        if (! $this->isSuccessPayload($response)) {
            throw new \RuntimeException(sprintf(
                'Device refused to delete user %s: %s',
                $biometricUserId,
                $this->errorMessage($response) ?? 'unknown error',
            ));
        }

        return true;
    }

    /**
     * Interpret an event the device pushed to us.
     *
     * Hikvision wraps pushed events as {"AccessControllerEvent": {...}} with
     * the timestamp at the envelope level, so the two are merged before the
     * usual mapping runs.
     */
    public function parsePushEvent(array $payload): ?DeviceEvent
    {
        $inner = $payload['AccessControllerEvent'] ?? $payload['AcsEvent'] ?? null;

        if (! is_array($inner)) {
            return null;
        }

        // Heartbeats and door-state messages arrive on the same endpoint and
        // carry no identity; they are not attendance.
        $merged = $inner + [
            'time' => $payload['dateTime'] ?? $payload['time'] ?? null,
            'major' => $payload['eventType'] ?? null,
        ];

        return $this->mapEvent($merged);
    }

    /**
     * Normalise one raw event item. Returns null for anything that is not a
     * person being identified.
     */
    private function mapEvent(array $item): ?DeviceEvent
    {
        $employeeNo = $item['employeeNoString']
            ?? $item['employeeNo']
            ?? $item['userID']
            ?? null;

        $employeeNo = $employeeNo === null ? null : trim((string) $employeeNo);

        // No identity means door sensors, tamper alarms and failed
        // authentication attempts - none of which are a member arriving.
        if ($employeeNo === null || $employeeNo === '' || $employeeNo === '0') {
            return null;
        }

        $time = $item['time'] ?? $item['dateTime'] ?? null;

        if ($time === null) {
            return null;
        }

        try {
            // The device stamps its own local time with an offset; convert to
            // application time so everything downstream compares like for like.
            $occurredAt = Carbon::parse($time)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        return new DeviceEvent(
            biometricUserId: $employeeNo,
            occurredAt: $occurredAt,
            verification: $this->mapVerification($item),
            direction: $this->mapDirection($item),
            deviceUserName: $item['name'] ?? null,
            raw: $item,
        );
    }

    /**
     * SRS 11 - Use the reader's own attendance status when it has one.
     *
     * Hikvision terminals configured for attendance report checkIn/checkOut
     * directly. When the field is absent or "undefined" the device is not in
     * attendance mode, and the direction is left for AttendanceService to
     * work out from the device's configured role and the member's open visit.
     */
    private function mapDirection(array $item): AttendanceDirection
    {
        return match (strtolower((string) ($item['attendanceStatus'] ?? ''))) {
            'checkin', 'breakout' => AttendanceDirection::IN,
            'checkout', 'breakin' => AttendanceDirection::OUT,
            default => AttendanceDirection::UNKNOWN,
        };
    }

    private function mapVerification(array $item): VerificationType
    {
        $mode = strtolower((string) ($item['currentVerifyMode'] ?? ''));

        return match (true) {
            $mode === '' => VerificationType::FINGERPRINT,
            str_contains($mode, 'fp') => VerificationType::FINGERPRINT,
            str_contains($mode, 'face') => VerificationType::FACE,
            str_contains($mode, 'card') => VerificationType::CARD,
            str_contains($mode, 'pw') => VerificationType::PASSWORD,
            default => VerificationType::OTHER,
        };
    }

    /**
     * @param  int  $retries  total attempts, not extra ones. 1 means "try once".
     */
    private function client(int $retries = 2): PendingRequest
    {
        return Http::baseUrl($this->device->base_url)
            ->timeout((int) config('gms.biometric.http_timeout', 15))
            // A reader on a flaky in-house LAN drops the odd connection;
            // retrying costs less than a missed check-in.
            ->retry($retries, 250, throw: false)
            ->withOptions([
                // ISAPI is digest, not basic. Guzzle handles the challenge.
                'auth' => [$this->device->username, $this->device->password, 'digest'],
                'verify' => $this->shouldVerifyTls(),
            ])
            ->acceptJson();
    }

    /**
     * Whether to check the reader's TLS certificate.
     *
     * Access terminals ship self-signed certificates, so verifying one on the
     * gym LAN would fail every HTTPS device there and help nobody: an attacker
     * able to intercept that traffic is already inside the building's network.
     *
     * Reaching the reader through a tunnel is a different situation entirely.
     * The address is then a public hostname, the traffic crosses the internet,
     * and every request carries the device's administrator credentials in a
     * digest exchange. Skipping the certificate check there would let anyone
     * positioned on the path present their own certificate and collect them.
     *
     * The test is the address itself rather than a setting, so that nobody has
     * to remember to change a checkbox when they move a device behind a
     * tunnel — which is exactly the moment the check starts mattering.
     */
    private function shouldVerifyTls(): bool
    {
        $host = parse_url($this->device->base_url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return true;
        }

        // A literal private or reserved address is a LAN device: 192.168.x.x,
        // 10.x.x.x, 172.16-31.x.x, 127.x.x.x and the IPv6 equivalents.
        $isPublicIp = filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $isPublicIp;
        }

        // A hostname. `.local` and a bare name with no dot are LAN naming;
        // anything else resolves on the public internet and is verified.
        return ! (str_ends_with($host, '.local') || ! str_contains($host, '.'));
    }

    /**
     * Run one request, turning a transport failure into a message a
     * receptionist can act on.
     *
     * Without this, "cURL error 28: Failed to connect to 192.0.2.10 port 80
     * after 10011 ms" reaches the screen of someone whose job is to sign
     * members in, and tells them nothing they can do about it.
     *
     * @param  \Closure(): Response  $request
     */
    private function send(\Closure $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new \RuntimeException($this->friendlyError($e), previous: $e);
        }
    }

    private function assertOk(Response $response, string $action): void
    {
        if ($response->status() === 401) {
            throw new \RuntimeException("Authentication failed while trying to {$action}. Check the device credentials.");
        }

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'Device returned HTTP %d while trying to %s.%s',
                $response->status(),
                $action,
                ($msg = $this->errorMessage($response)) ? " {$msg}" : '',
            ));
        }
    }

    /**
     * ISAPI answers a rejected write with HTTP 200 and a status block, so the
     * HTTP code alone is not enough to tell success from failure.
     */
    private function isSuccessPayload(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $status = $response->json('statusCode');

        if ($status !== null) {
            return (int) $status === 1;
        }

        $body = $response->json();

        if (is_array($body) && isset($body['UserInfoOutList'])) {
            foreach ($body['UserInfoOutList']['UserInfoOut'] ?? [] as $entry) {
                if ((int) ($entry['statusCode'] ?? 1) !== 1) {
                    return false;
                }
            }
        }

        return true;
    }

    private function errorMessage(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        return $body['statusString']
            ?? $body['subStatusCode']
            ?? $body['errorMsg']
            ?? null;
    }

    /** ISO 8601 with an explicit offset, which is what ISAPI expects. */
    private function formatTime(CarbonInterface $time): string
    {
        return Carbon::instance($time->toDateTime())
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d\TH:i:sP');
    }

    /** @return array<string, string> */
    private function parseXml(string $body): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body);

            if ($xml === false) {
                return [];
            }

            $values = [];

            foreach ((array) $xml as $key => $value) {
                /*
                 * Only the text children are wanted, and only they are safe
                 * to cast.
                 *
                 * Casting a SimpleXMLElement to an array does not produce a
                 * flat map of strings. The element's own attributes arrive
                 * under '@attributes' as a nested array, a repeated child
                 * arrives as an array of values, and a child with children of
                 * its own arrives as another SimpleXMLElement. An earlier
                 * version ran array_map('strval', ...) across the lot, which
                 * worked on the fake driver's tidy XML and raised "Array to
                 * string conversion" on the first real device - every ISAPI
                 * response carries version and xmlns attributes on its root.
                 */
                if (is_scalar($value)) {
                    $values[$key] = (string) $value;
                }
            }

            return $values;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Turn a transport exception into something reception can act on. */
    private function friendlyError(\Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'cURL error 28') || str_contains($message, 'timed out') => 'Device did not respond in time. Check that it is powered on and reachable on the network.',
            str_contains($message, 'cURL error 7') || str_contains($message, 'Connection refused') => "Could not connect to {$this->device->ip_address}:{$this->device->port}. Check the IP address and port.",
            str_contains($message, 'Could not resolve host') => 'Could not resolve the device address.',
            default => $message,
        };
    }
}
