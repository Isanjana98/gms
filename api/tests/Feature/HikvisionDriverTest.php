<?php

namespace Tests\Feature;

use App\Models\BiometricDevice;
use App\Services\Biometric\Drivers\HikvisionDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Hikvision driver against responses a real terminal actually sends.
 *
 * The rest of the biometric suite runs on FakeDeviceDriver, which is the right
 * way to test the sync logic but tests nothing about parsing ISAPI. The first
 * time this driver met hardware — a DS-K1T321MFWX on firmware V3.9.2 — it fell
 * over on the device-info response, because every ISAPI payload carries
 * attributes on its root element and the parser assumed a flat map of strings.
 *
 * The XML below is copied verbatim from that device. Fixtures invented by hand
 * are exactly how that bug survived.
 */
class HikvisionDriverTest extends TestCase
{
    use RefreshDatabase;

    /** Verbatim from a DS-K1T321MFWX, firmware V3.9.2, build 240222. */
    private const DEVICE_INFO = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <DeviceInfo version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">
    <deviceName>Access Controller</deviceName>
    <deviceID>255</deviceID>
    <model>DS-K1T321MFWX</model>
    <serialNumber>DS-K1T321MFWX20240222V030902ENGL6369505</serialNumber>
    <macAddress>88:de:39:52:db:5e</macAddress>
    <firmwareVersion>V3.9.2</firmwareVersion>
    <firmwareReleasedDate>build 240222</firmwareReleasedDate>
    <deviceType>ACS</deviceType>
    <subDeviceType>accessControlTerminal</subDeviceType>
    <manufacturer>hikvision</manufacturer>
    </DeviceInfo>
    XML;

    private function device(): BiometricDevice
    {
        return BiometricDevice::create([
            'branch_id' => $this->branch->id,
            'code' => 'ENT-01',
            'name' => 'Main Door',
            'driver' => 'hikvision',
            'ip_address' => '192.168.8.104',
            'port' => 80,
            'username' => 'admin',
            'password' => 'secret',
            'mode' => 'AUTO',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_reads_the_model_and_serial_from_a_real_device_info_response(): void
    {
        Http::fake(['*' => Http::response(self::DEVICE_INFO, 200)]);

        $info = (new HikvisionDriver($this->device()))->testConnection();

        // The regression: the root element's version and xmlns attributes
        // arrive as a nested array, and casting the lot to string threw
        // "Array to string conversion" before anything was read.
        $this->assertTrue($info->reachable);
        $this->assertSame('DS-K1T321MFWX', $info->model);
        $this->assertSame('DS-K1T321MFWX20240222V030902ENGL6369505', $info->serialNumber);
        $this->assertSame('Access Controller', $info->deviceName);
        $this->assertStringContainsString('V3.9.2', (string) $info->firmware);
        $this->assertNull($info->error);
    }

    #[Test]
    public function a_locked_out_account_is_reported_as_an_authentication_failure(): void
    {
        // What the terminal sends after too many failed logins. It is a 401,
        // but the password may be perfectly correct - the device is simply
        // refusing everyone for half an hour.
        Http::fake(['*' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <userCheck version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">
            <statusValue>401</statusValue>
            <statusString>Unauthorized</statusString>
            <isActivated>true</isActivated>
            <lockStatus>lock</lockStatus>
            <unlockTime>1662</unlockTime>
            <retryLoginTime>0</retryLoginTime>
            </userCheck>
            XML, 401)]);

        $info = (new HikvisionDriver($this->device()))->testConnection();

        $this->assertFalse($info->reachable);
        $this->assertStringContainsString('username and password', (string) $info->error);
    }

    #[Test]
    public function a_device_code_containing_a_slash_is_refused(): void
    {
        // The code becomes a path segment in /api/biometric/push/{code}. A
        // slash was accepted once and produced a push URL that could never
        // route - the hint "used in the device's push URL" had been read as an
        // instruction to paste the URL itself.
        $this->actingAs($this->admin())
            ->postJson('/api/biometric/devices', [
                'code' => '/api/biometric/push/ENT-01',
                'name' => 'Main Door',
                'ip_address' => '192.168.8.104',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    #[Test]
    public function an_ordinary_device_code_is_accepted(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/biometric/devices', [
                'code' => 'ENT-01',
                'name' => 'Main Door',
                'ip_address' => '192.168.8.104',
                'port' => 80,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('biometric_devices', ['code' => 'ENT-01']);
    }

    #[Test]
    public function an_unreachable_device_is_a_message_not_an_exception(): void
    {
        // SRS 14: an offline reader is an expected state. testConnection must
        // never throw, or the device list cannot render.
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $info = (new HikvisionDriver($this->device()))->testConnection();

        $this->assertFalse($info->reachable);
        $this->assertStringContainsString('did not respond in time', (string) $info->error);
    }

    #[Test]
    public function malformed_xml_does_not_bring_the_test_button_down(): void
    {
        Http::fake(['*' => Http::response('<<not xml at all', 200)]);

        $info = (new HikvisionDriver($this->device()))->testConnection();

        // Reachable, because it answered - just with nothing we can read.
        $this->assertTrue($info->reachable);
        $this->assertNull($info->model);
    }
}
