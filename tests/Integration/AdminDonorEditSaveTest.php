<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use WP_REST_Request;

/**
 * Edit donor details is the only way to correct a donor's own record in the
 * admin, and the dialog posts the whole form back every time. Most donors have
 * no address, so the address it posts back carries an empty country: refused at
 * the boundary, the save takes the name, the email, the phone, the company and
 * the type down with it and the operator is told "Invalid parameter(s)".
 *
 * The body here is built from the profile response the way the dialog builds
 * it, so the two cannot drift apart.
 */
final class AdminDonorEditSaveTest extends IntegrationTestCase
{
    public function test_a_donor_with_no_address_can_still_be_corrected(): void
    {
        $donor = $this->seedDonor('noaddress@example.test', 'Sara', 'Mullen');

        $res = $this->save($donor, ['last_name' => 'Mullen-Reid']);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame('Mullen-Reid', (string) $this->reload($donor)->last_name);
    }

    /** An address captured without a country is not a reason to refuse the edit, or to lose the address. */
    public function test_an_address_with_no_country_survives_the_save(): void
    {
        $donor = $this->seedDonor('nocountry@example.test', 'Luc', 'Rossi');
        $service = Plugin::instance()->container->get(DonorService::class);
        $service->setEncryptedField(
            $donor,
            'address_encrypted',
            $service->addressPayload(['line1' => '12 Rue Lepic', 'city' => 'Paris'])
        );

        $res = $this->save($this->reload($donor), ['first_name' => 'Lucien']);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $donor = $this->reload($donor);
        $this->assertSame('Lucien', (string) $donor->first_name);
        $this->assertSame(
            ['line1' => '12 Rue Lepic', 'city' => 'Paris'],
            $service->decryptAddressStruct($donor),
            'the address is written back as it stood'
        );
    }

    public function test_a_malformed_address_country_is_still_refused(): void
    {
        $donor = $this->seedDonor('badcountry@example.test', 'Ann', 'Keele');

        $res = $this->save($donor, ['last_name' => 'Keele-Barr'], ['country' => 'Germany']);

        $this->assertSame(400, $res->get_status());
        $this->assertSame('Keele', (string) $this->reload($donor)->last_name, 'and the edit is not half applied');
    }

    /**
     * @param array<string,mixed> $edits
     * @param array<string,mixed> $addressEdits
     */
    public function test_a_multibyte_name_is_stored_at_its_full_length(): void
    {
        $donor = $this->seedDonor('kanji@example.test', 'Ken', 'Tanaka');
        $name  = str_repeat('東', 40);

        $res = $this->save($donor, ['last_name' => $name]);

        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame($name, (string) $this->reload($donor)->last_name);
    }

    private function save(Donor $donor, array $edits, array $addressEdits = []): \WP_REST_Response
    {
        $profile = (array) rest_do_request(
            new WP_REST_Request('GET', '/fundkit/v1/admin/donors/' . (int) $donor->id . '/profile')
        )->get_data();
        $d = (array) ($profile['donor'] ?? []);

        // EditPanel's initial state, field for field.
        $addr = is_array($d['address_parts'] ?? null) ? $d['address_parts'] : [];
        $form = [
            'email'      => $d['email'] ?? '',
            'first_name' => $d['first_name'] ?? '',
            'last_name'  => $d['last_name'] ?? '',
            'country'    => $d['country'] ?? '',
            'company'    => $d['company'] ?? '',
            'donor_type' => $d['donor_type'] ?? 'individual',
            'phone'      => $d['phone'] ?? '',
            'address'    => [
                'line1'   => $addr['line1']   ?? '',
                'line2'   => $addr['line2']   ?? '',
                'city'    => $addr['city']    ?? '',
                'region'  => $addr['region']  ?? '',
                'postal'  => $addr['postal']  ?? '',
                'country' => $addr['country'] ?? '',
            ],
        ];
        $form            = array_merge($form, $edits);
        $form['address'] = array_merge($form['address'], $addressEdits);

        $req = new WP_REST_Request('PATCH', '/fundkit/v1/admin/donors/' . (int) $donor->id);
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($form));

        return rest_do_request($req);
    }

    private function seedDonor(string $email, string $first, string $last): Donor
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => $email,
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => $first, 'last_name' => $last],
        ]));
        rest_do_request($req);

        return Plugin::instance()->container->get(DonorService::class)->findByEmail($email);
    }

    private function reload(Donor $donor): Donor
    {
        return Donor::query()->find('id', (int) $donor->id);
    }
}
