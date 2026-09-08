<?php

declare(strict_types=1);

namespace FundKit\Rest\Admin;
use FundKit\Analytics\ErrorLog;
use FundKit\Donations\Donation;
use FundKit\Donations\DonationService;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorAvatars;
use FundKit\Donors\DonorMetricsService;
use FundKit\Donors\DonorNoteRepository;
use FundKit\Donors\DonorRepository;
use FundKit\Donors\DonorService;
use FundKit\Donors\EmailAlreadyAssignedException;
use FundKit\Foundation\Auth\Capabilities;
use FundKit\Recurring\RecurringPlan;
use FundKit\Recurring\RecurringPlanRepository;
use FundKit\Rest\Paging;
use FundKit\Vendor\Queryable\DB;
use InvalidArgumentException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** @since 1.0.0 */
final class DonorsController
{
    private const NAMESPACE = 'fundkit/v1';

    // TEXT holds 65,535 bytes and a note body is stored as AES-GCM ciphertext,
    // base64 of iv + tag + ciphertext. An overflow truncates silently and the
    // tag never verifies again, so the note reads back empty forever. maxLength
    // counts characters, so this is the four-byte worst case with room over.
    private const NOTE_MAX_LENGTH = 12000;

    /** @since 1.0.0 */
    public function __construct(
        private DonorRepository $donors,
        private DonorService $donorService,
        private DonorMetricsService $metrics,
        private DonorNoteRepository $notes,
        private DonationService $donationService,
        private DonorAvatars $avatars,
    ) {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/donors', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
                'orderby'  => ['type' => 'string', 'default' => 'last_donation_at'],
                'order'    => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                'country'    => ['type' => 'string'],
                'donor_type' => ['type' => 'string', 'enum' => Donor::TYPES],
                'search'     => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'stats'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'country'    => ['type' => 'string'],
                'donor_type' => ['type' => 'string', 'enum' => Donor::TYPES],
                'search'     => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/insights', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'insights'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)/profile', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'profile'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)/events', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'events'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'id'       => ['type' => 'integer', 'required' => true],
                'page'     => ['type' => 'integer', 'default' => 1,  'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
                'order'    => ['type' => 'string',  'default' => 'desc', 'enum' => ['asc', 'desc']],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [$this, 'update'],
            'permission_callback' => static fn () => Capabilities::userCan('fundkit_edit_donors'),
            'args'                => [
                'id'         => ['type' => 'integer', 'required' => true],
                'email'      => ['type' => 'string', 'format' => 'email'],
                'first_name' => ['type' => 'string'],
                'last_name'  => ['type' => 'string'],
                'country'    => ['type' => 'string'],
                'company'    => ['type' => 'string'],
                'phone'      => ['type' => 'string'],
                'address'    => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'line1'   => ['type' => 'string', 'maxLength' => 200],
                        'line2'   => ['type' => 'string', 'maxLength' => 200],
                        'city'    => ['type' => 'string', 'maxLength' => 100],
                        'region'  => ['type' => 'string', 'maxLength' => 100],
                        'postal'  => ['type' => 'string', 'maxLength' => 20],
                        // Optional, so the empty string has to pass: most
                        // donors have no address country, and the edit dialog
                        // sends the whole address back whatever it holds.
                        'country' => ['type' => 'string', 'pattern' => '^([A-Za-z]{2})?$'],
                    ],
                ],
                'donor_type' => ['type' => 'string', 'enum' => Donor::TYPES],
                'public_hidden' => ['type' => 'boolean'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/at-risk', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'atRisk'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'page'     => ['type' => 'integer', 'default' => 1,  'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/at-risk/export', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'atRiskExport'],
            // Bulk PII (names + emails): gate on the export cap, not just view.
            'permission_callback' => static fn () => Capabilities::userCan('fundkit_export_donors'),
        ]);

        // Minting a portal login is an action the admin takes, never something
        // a page load does on their behalf: whoever opens the link is signed in
        // as the donor. The donor can take it back with sign out everywhere.
        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)/portal-link', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'issuePortalLink'],
            'permission_callback' => static fn () => Capabilities::userCan('fundkit_edit_donors'),
            'args'                => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)/notes', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'createNote'],
            'permission_callback' => static fn () => Capabilities::userCan('fundkit_edit_donors'),
            'args'                => [
                'id'   => ['type' => 'integer', 'required' => true],
                'body' => ['type' => 'string',  'required' => true, 'maxLength' => self::NOTE_MAX_LENGTH],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/notes/(?P<note_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'deleteNote'],
            'permission_callback' => static fn () => Capabilities::userCan('fundkit_edit_donors'),
            'args'                => [
                'note_id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)/export', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'exportPersonalData'],
            'permission_callback' => static fn () => Capabilities::userCan('fundkit_export_donors'),
            'args'                => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete'],
            // Erasing and deleting are both irreversible, so they answer to the
            // same capability.
            'permission_callback' => static fn () => Capabilities::userCan('fundkit_redact_donors'),
            'args'                => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)/redact', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'redact'],
            'permission_callback' => static fn () => Capabilities::userCan('fundkit_redact_donors'),
            'args'                => [
                'id'           => ['type' => 'integer', 'required' => true],
                'confirmation' => ['type' => 'string',  'required' => true],
            ],
        ]);
    }

    /** @since 1.0.0 */
    public function insights(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->metrics->insights(), 200);
    }

    /** @since 1.0.0 */
    public function profile(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $payload = $this->metrics->profile((int) $request['id'], Capabilities::userCan('fundkit_edit_donors'));
        if (! $payload) {
            return new WP_Error('fundkit_not_found', __('Donor not found.', 'fundraising-toolkit'), ['status' => 404]);
        }
        return new WP_REST_Response($payload, 200);
    }

    /** @since 1.0.0 */
    public function events(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->donors->findById((int) $request['id']);
        if (! $donor) {
            return new WP_Error('fundkit_not_found', __('Donor not found.', 'fundraising-toolkit'), ['status' => 404]);
        }

        $perPage = (int) $request['per_page'];
        $result  = $this->metrics->eventsPage(
            (int) $donor->id,
            (int) $request['page'],
            $perPage,
            (string) $request['order'],
        );

        $response = new WP_REST_Response($result['items'], 200);
        $response->header('X-WP-Total',      (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($result['total'] / max(1, $perPage))));
        return $response;
    }

    /** @since 1.0.0 */
    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->donors->findById((int) $request['id']);
        if (! $donor) {
            return new WP_Error('fundkit_not_found', __('Donor not found.', 'fundraising-toolkit'), ['status' => 404]);
        }
        // This handler writes name/company/country via a direct UPDATE and
        // phone/address via setEncryptedField, neither of which passes through
        // DonorService::editProfile's guard, so the whole edit is blocked here
        // or those writes would re-populate an erased row.
        if ($donor->redacted_at !== null) {
            return new WP_Error('fundkit_donor_redacted', __('This donor has been erased and can no longer be edited.', 'fundraising-toolkit'), ['status' => 422]);
        }

        // Present keys set the value, empty string clears to NULL. Direct
        // UPDATE because model save() drops NULLs via array_filter.
        $params = $request->get_json_params() ?: $request->get_body_params();
        if (! $params) $params = [];
        $update = [];

        $applyText = function (string $field, ?int $maxLen = null) use ($params, &$update): void {
            if (! array_key_exists($field, $params)) return;
            $value = $params[$field];
            $value = $value === null ? null : trim((string) $value);
            if ($value === '') $value = null;
            if ($value !== null && $maxLen !== null) {
                $value = mb_substr($value, 0, $maxLen);
            }
            $update[$field] = $value;
        };

        $applyText('first_name', 100);
        $applyText('last_name',  100);
        $applyText('company',    150);

        if (array_key_exists('country', $params)) {
            $country = $params['country'];
            $country = $country === null || $country === '' ? null : strtoupper(substr((string) $country, 0, 2));
            $update['country'] = $country;
        }

        // The moderation lever. Redaction is the only other way to take a
        // donor off the public pages and it destroys them, which is no answer
        // to a bad picture or an unwanted name.
        if (array_key_exists('public_hidden', $params)) {
            $update['public_hidden_at'] = $params['public_hidden']
                ? gmdate('Y-m-d H:i:s')
                : null;
            // Already hidden stays hidden at its original time rather than
            // being restamped by an unrelated save.
            if ($params['public_hidden'] && $donor->public_hidden_at !== null) {
                unset($update['public_hidden_at']);
            }
        }

        if (array_key_exists('donor_type', $params)) {
            $type = (string) $params['donor_type'];
            if (in_array($type, Donor::TYPES, true)) {
                $update['donor_type'] = $type;
            }
        }

        foreach ($update as $field => $value) {
            if ($donor->$field === $value) unset($update[$field]);
        }

        // One transaction so a partial failure can't leave mismatched
        // name / encrypted PII / hash. donor.updated fires after commit.
        //
        // Phone and address count towards it: the admin dialog PATCHes the
        // whole form, so a correction to only the phone number leaves $update
        // empty, and firing on that alone meant a CRM kept the old number for
        // good. changeEmail fires the event itself, so an email change is not
        // counted here and cannot double it.
        $changed = false;

        try {
            DB::transaction(function () use ($donor, $params, $update, &$changed): void {
                if ($update) {
                    $update['updated_at'] = gmdate('Y-m-d H:i:s');
                    DB::table('fundkit_donors')->where('id', $donor->id)->update($update);
                    $changed = true;

                    // The email write below saves the whole model, so the model
                    // has to be carrying what this just wrote. Left stale, its
                    // save puts the old name back and the admin is told the
                    // edit worked.
                    foreach ($update as $field => $value) {
                        $donor->$field = $value;
                    }
                }

                if (array_key_exists('phone', $params)) {
                    $phone = is_string($params['phone']) ? trim($params['phone']) : '';
                    if ($phone !== ($this->donorService->decryptPhone($donor) ?? '')) {
                        $this->donorService->setEncryptedField($donor, 'phone_encrypted', $phone);
                        $changed = true;
                    }
                }
                if (array_key_exists('address', $params)) {
                    $addr    = is_array($params['address']) ? $params['address'] : null;
                    $payload = $this->donorService->addressPayload($addr);
                    // Both sides through addressPayload, so a reordered or
                    // whitespace-only difference is not a change.
                    $current = $this->donorService->addressPayload(
                        $this->donorService->decryptAddressStruct($donor)
                    );
                    if ($payload !== $current) {
                        $this->donorService->setEncryptedField($donor, 'address_encrypted', $payload);
                        $changed = true;
                    }
                }

                if (array_key_exists('email', $params) && is_string($params['email']) && trim($params['email']) !== '') {
                    $this->donorService->changeEmail($donor, trim($params['email']));
                }
            });
        } catch (EmailAlreadyAssignedException $e) {
            return new WP_Error(
                'fundkit_email_collision',
                /* translators: %d: donor id that already owns the requested email */
                sprintf(__('Another donor (#%d) already uses that email. Merge donors first if you want to consolidate them.', 'fundraising-toolkit'), $e->existingDonorId),
                ['status' => 409, 'existing_donor_id' => $e->existingDonorId]
            );
        } catch (InvalidArgumentException $e) {
            return new WP_Error('fundkit_invalid_email', $e->getMessage(), ['status' => 422]);
        }

        if ($changed) {
            do_action('fundkit.donor.updated', $this->donors->findById($donor->id));
        }

        return new WP_REST_Response($this->metrics->profile($donor->id, Capabilities::userCan('fundkit_edit_donors')), 200);
    }

    /** @since 1.0.0 */
    public function atRisk(WP_REST_Request $request): WP_REST_Response
    {
        $perPage = (int) ($request['per_page'] ?? 25);
        $result = $this->metrics->atRisk(
            Paging::page($request['page'] ?? null),
            $perPage,
        );
        $response = new WP_REST_Response($result['rows'], 200);
        $response->header('X-WP-Total',      (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($result['total'] / max(1, $perPage))));
        return $response;
    }

    /** @since 1.0.0 */
    public function atRiskExport(WP_REST_Request $request): WP_REST_Response
    {
        $csv      = $this->metrics->atRiskCsv();
        $filename = 'fundkit-at-risk-' . gmdate('Y-m-d') . '.csv';
        $route    = $request->get_route();

        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($route, $csv, $filename) {
            if ((string) $req->get_route() !== $route) return $served;
            $server->send_header('Content-Type', 'text/csv; charset=utf-8');
            $server->send_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $csv comes from DonorMetricsService::atRiskCsv(), whose cells go through Csv::writeRow() (fputcsv quoting plus formula-injection prefixing), and is sent under its own text/csv header; escaping it would corrupt the file.
            echo $csv;
            return true;
        }, 10, 4);

        $response = new WP_REST_Response(null, 200);
        $response->set_headers([
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
        return $response;
    }

    /** @since 1.0.0 */
    /**
     * @since 1.0.0
     */
    public function issuePortalLink(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->donors->findById((int) $request['id']);
        if (! $donor) {
            return new WP_Error('fundkit_donor_not_found', __('Donor not found.', 'fundraising-toolkit'), ['status' => 404]);
        }

        // Asked here rather than read off a null, because issuePortalLink also
        // returns null when minting throws. Collapsing the two told an operator
        // the donor was erased whenever the write failed, and the button is not
        // even rendered for an erased donor, so the message was always wrong.
        if ($donor->redacted_at !== null) {
            return new WP_Error(
                'fundkit_portal_link_unavailable',
                __('A sign-in link cannot be issued for an erased donor.', 'fundraising-toolkit'),
                ['status' => 409]
            );
        }

        $link = $this->metrics->issuePortalLink($donor);
        if ($link === null) {
            return new WP_Error(
                'fundkit_portal_link_failed',
                __('The sign-in link could not be created. Please try again.', 'fundraising-toolkit'),
                ['status' => 500]
            );
        }

        // The expiry travels with the link so the screen states the deadline
        // this token actually carries instead of repeating a number.
        return new WP_REST_Response([
            'magic_link_url' => $link['url'],
            'expires_at'     => $link['expires_at'],
        ], 201);
    }
    public function createNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donorId = (int) $request['id'];
        $donor   = $this->donors->findById($donorId);
        if (! $donor) {
            return new WP_Error('fundkit_not_found', __('Donor not found.', 'fundraising-toolkit'), ['status' => 404]);
        }

        // redact() early-returns on an already-redacted row, so free text
        // written after an erasure is reachable by no erasure path: it would
        // sit against that donor for good, and a note is where a name, a phone
        // number or a reason for the erasure gets typed.
        if ($donor->redacted_at !== null) {
            return new WP_Error(
                'fundkit_donor_redacted',
                __('This donor has been erased, so nothing further can be recorded against them.', 'fundraising-toolkit'),
                ['status' => 422]
            );
        }

        $params = $request->get_json_params() ?: $request->get_body_params();
        $body   = trim((string) ($params['body'] ?? ''));
        if ($body === '') {
            return new WP_Error('fundkit_invalid', __('Note body is required.', 'fundraising-toolkit'), ['status' => 400]);
        }
        $note = $this->notes->create($donorId, $body, get_current_user_id() ?: null);
        return new WP_REST_Response($note, 201);
    }

    /** @since 1.0.0 */
    public function deleteNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $noteId = (int) $request['note_id'];
        $note = $this->notes->findById($noteId);
        if (! $note) {
            return new WP_Error('fundkit_not_found', __('Note not found.', 'fundraising-toolkit'), ['status' => 404]);
        }
        if (! DonorNoteRepository::deletableBy($note, get_current_user_id())) {
            return new WP_Error('fundkit_forbidden', __('You cannot delete this note.', 'fundraising-toolkit'), ['status' => 403]);
        }
        $this->notes->delete($noteId);
        return new WP_REST_Response(['deleted' => true], 200);
    }

    /** @since 1.0.0 */
    public function exportPersonalData(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->donors->findById((int) $request['id']);
        if (! $donor) {
            return new WP_Error('fundkit_not_found', __('Donor not found.', 'fundraising-toolkit'), ['status' => 404]);
        }

        $data = $this->metrics->exportData($donor->id);
        if ($data === null) {
            return new WP_Error('fundkit_not_found', __('Donor not found.', 'fundraising-toolkit'), ['status' => 404]);
        }
        $bundle = [
            'exported_at' => gmdate('c'),
            'donor'       => $data['donor'],
            'donations'   => $this->enrichDonations($donor->id, (array) $data['donations']),
            'recurring'   => $data['recurring'],
            'receipts'    => $data['receipts'],
            'consents'    => $data['consents'],
            'notes'       => $data['notes'],
            'events'      => $data['events'],
        ];

        $json     = wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filename = sprintf('fundkit-donor-%d-%s.json', $donor->id, gmdate('Y-m-d'));
        $route    = $request->get_route();

        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($route, $json, $filename) {
            if ((string) $req->get_route() !== $route) return $served;

            $server->send_header('Content-Type', 'application/json; charset=utf-8');
            $server->send_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $json is wp_json_encode() output, already escaped for the JSON grammar, sent under its own application/json header; escaping it again would corrupt the file.
            echo $json;
            return true;
        }, 10, 4);

        $response = new WP_REST_Response(null, 200);
        $response->set_headers([
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
        return $response;
    }

    /**
     * A DSAR must return the donor's per-donation personal data: the custom
     * form-field answers they submitted and the name they gave for that
     * donation. Erasure clears both, so a later export reports them empty.
     *
     * @since 1.0.0
     */
    private function enrichDonations(int $donorId, array $donations): array
    {
        if ($donations === []) {
            return $donations;
        }

        $models = [];
        foreach (Donation::query()->where('donor_id', $donorId)->getAll() as $d) {
            $models[(int) $d->id] = $d;
        }

        foreach ($donations as $i => $row) {
            $id = (int) ($row['id'] ?? 0);
            $model = $models[$id] ?? null;

            $donations[$i]['custom_data'] = $model
                ? $this->donationService->decryptCustomData($model)
                : [];
            $donations[$i]['donor_name_given'] = $model
                ? (trim((string) $model->donor_first_name . ' ' . (string) $model->donor_last_name) ?: null)
                : null;
        }

        return $donations;
    }

    /**
     * Deletion refuses donors with donations; no erasure confirmation is needed.
     *
     * @since 1.0.0
     */
    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->donors->findById((int) $request['id']);
        if (! $donor) {
            return new WP_Error('fundkit_not_found', __('Donor not found.', 'fundraising-toolkit'), ['status' => 404]);
        }

        $reason = $this->donorService->undeletableReason($donor);
        if ($reason !== null) {
            return new WP_Error('fundkit_donor_not_deletable', $reason, ['status' => 409]);
        }

        try {
            $this->donorService->delete($donor);
        } catch (InvalidArgumentException $e) {
            // The receipt and refund guards run inside the transaction, where
            // the pre-check cannot see them, and they mean the same thing to
            // the operator as the pre-check's own refusal.
            return new WP_Error('fundkit_donor_not_deletable', $e->getMessage(), ['status' => 409]);
        } catch (Throwable $e) {
            ErrorLog::record('admin.donor.delete', $e->getMessage(), ['donor_id' => (int) $donor->id]);

            return new WP_Error(
                'fundkit_delete_failed',
                __('The donor was not deleted. The reason is in the log under Tools.', 'fundraising-toolkit'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response(['deleted' => true, 'id' => (int) $request['id']], 200);
    }

    /**
     * Confirmation must match the donor's current email, or 'DONOR_<id>' when
     * there is no readable email.
     *
     * @since 1.0.0
     */
    public function redact(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->donors->findById((int) $request['id']);
        if (! $donor) {
            return new WP_Error('fundkit_not_found', __('Donor not found.', 'fundraising-toolkit'), ['status' => 404]);
        }
        if ($donor->redacted_at !== null) {
            return new WP_Error('fundkit_already_redacted', __('This donor is already redacted.', 'fundraising-toolkit'), ['status' => 409]);
        }

        $params = $request->get_json_params() ?: $request->get_body_params() ?: [];
        $confirmation = trim((string) ($params['confirmation'] ?? ''));

        $expected = $this->donorService->decryptEmail($donor) ?: sprintf('DONOR_%d', $donor->id);
        if ($confirmation === '' || strcasecmp($confirmation, $expected) !== 0) {
            return new WP_Error(
                'fundkit_confirmation_mismatch',
                __('Confirmation does not match the donor email. Redact cancelled.', 'fundraising-toolkit'),
                ['status' => 422],
            );
        }

        // The cancellations run before the erasure's transaction, so a gateway
        // that refuses half way leaves the earlier plans genuinely stopped.
        $liveBefore = $this->cancellablePlanIds($donor);

        try {
            $this->donorService->redact($donor);
        } catch (Throwable $e) {
            ErrorLog::record('admin.donor.redact', $e->getMessage(), ['donor_id' => (int) $donor->id]);

            $stillLive = $this->cancellablePlanIds($donor);
            $stopped   = count($liveBefore) - count($stillLive);

            if ($liveBefore === []) {
                return new WP_Error(
                    'fundkit_redact_failed',
                    __('The donor was not erased. The reason is in the log under Tools.', 'fundraising-toolkit'),
                    ['status' => 500],
                );
            }

            return new WP_Error(
                'fundkit_redact_failed',
                $stopped > 0
                    ? sprintf(
                        /* translators: 1: how many recurring plans were stopped, 2: how many are still billing. */
                        _n(
                            'The donor was not erased. %1$d recurring plan was stopped first, and %2$d is still billing: cancel it at the gateway, then try again.',
                            'The donor was not erased. %1$d recurring plans were stopped first, and %2$d are still billing: cancel them at the gateway, then try again.',
                            $stopped,
                            'fundraising-toolkit'
                        ),
                        $stopped,
                        count($stillLive)
                    )
                    : __('The donor was not erased: their recurring plans could not be stopped. Cancel them at the gateway, then try again.', 'fundraising-toolkit'),
                [
                    'status'           => 502,
                    'stopped'          => $stopped,
                    'still_billing'    => $stillLive,
                ],
            );
        }

        return new WP_REST_Response([
            'redacted'    => true,
            'redacted_at' => $donor->redacted_at,
            'public_hidden' => $donor->public_hidden_at !== null,
            'avatar_url'    => $this->avatars->adminUrl($donor),
        ], 200);
    }

    /**
     * @return list<int>
     */
    private function cancellablePlanIds(Donor $donor): array
    {
        return array_map(
            static fn ($p): int => (int) $p->id,
            RecurringPlan::query()
                ->where('donor_id', (int) $donor->id)
                ->whereIn('status', RecurringPlanRepository::CANCELLABLE_STATUSES)
                ->getAll()
        );
    }

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return Capabilities::userCan('fundkit_view_donors');
    }

    /** @since 1.0.0 */
    public function stats(WP_REST_Request $request): WP_REST_Response
    {
        $search = $request['search'] !== null ? trim((string) $request['search']) : '';

        $matchingIds = $search !== ''
            ? $this->donorService->findIdsBySearch($search)
            : [];

        $stats = $this->donors->aggregateAdmin([
            'country'      => $request['country']    !== null ? (string) $request['country'] : null,
            'donor_type'   => $request['donor_type'] !== null ? (string) $request['donor_type'] : null,
            'has_search'   => $search !== '',
            'matching_ids' => $matchingIds,
        ]);

        return new WP_REST_Response($stats, 200);
    }

    /** @since 1.0.0 */
    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $search = $request['search'] !== null ? trim((string) $request['search']) : '';

        $matchingIds = $search !== ''
            ? $this->donorService->findIdsBySearch($search)
            : [];

        $result = $this->donors->listAdmin([
            'page'         => Paging::page($request['page'] ?? null),
            'per_page'     => (int) ($request['per_page'] ?? 25),
            'orderby'      => (string) ($request['orderby'] ?? 'last_donation_at'),
            'order'        => (string) ($request['order']   ?? 'desc'),
            'country'      => $request['country']    !== null ? (string) $request['country'] : null,
            'donor_type'   => $request['donor_type'] !== null ? (string) $request['donor_type'] : null,
            'has_search'   => $search !== '',
            'matching_ids' => $matchingIds,
        ]);

        // Asked once for the page rather than once per row: a donor with no
        // live donation is one the operator made while testing, and the list
        // says so rather than showing them as an ordinary donor who gave
        // nothing.
        $testOnly = DonorRepository::testOnlyIdsAmong(
            array_map(static fn (Donor $d): int => (int) $d->id, $result['items'])
        );

        // The delete gate's own answer, asked once for the page. The screen
        // cannot work it out: a donation the counters ignore, a refunded one or
        // an abandoned attempt, still keeps the donor.
        $undeletable = $this->donorService->undeletableReasons($result['items']);

        $shaped = array_map(
            fn (Donor $d): array => [
                'id'                  => $d->id,
                'is_test_only'        => isset($testOnly[(int) $d->id]),
                'name'                => $this->donorName($d),
                'email'               => $this->donorService->decryptEmail($d),
                'country'             => $d->country,
                'donor_type'          => $d->donor_type,
                'donations_count'     => $d->donations_count,
                'total_donated_cents' => $d->total_donated_cents,
                'first_donation_at'   => $d->first_donation_at,
                'last_donation_at'    => $d->last_donation_at,
                'created_at'          => $d->created_at,
                'redacted'            => $d->redacted_at !== null,
                'deletable'           => ($undeletable[(int) $d->id] ?? null) === null,
                'avatar_url'          => $this->avatars->adminUrl($d),
            ],
            $result['items'],
        );

        $perPage = (int) ($request['per_page'] ?? 25);
        $response = new WP_REST_Response($shaped, 200);
        $response->header('X-WP-Total',      (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($result['total'] / max(1, $perPage))));
        return $response;
    }

    /** @since 1.0.0 */
    /**
     * A redacted donor is named as erased rather than as nameless: the dash a
     * missing name earns reads as data that was never collected.
     *
     * @since 1.0.0
     */
    private function donorName(Donor $d): string
    {
        if ($d->redacted_at !== null) {
            return __('[redacted]', 'fundraising-toolkit');
        }

        $full = trim(($d->first_name ?? '') . ' ' . ($d->last_name ?? ''));
        return $full !== '' ? $full : '-';
    }
}
