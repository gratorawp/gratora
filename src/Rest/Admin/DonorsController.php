<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Rest\Paging;
use Dono\Foundation\Auth\Capabilities;

use Dono\Donations\Donation;
use Dono\Donations\DonationService;
use Dono\Donors\Donor;
use Dono\Donors\DonorMetricsService;
use Dono\Donors\DonorNoteRepository;
use Dono\Donors\DonorRepository;
use Dono\Donors\DonorService;
use Dono\Donors\EmailAlreadyAssignedException;
use InvalidArgumentException;
use Dono\Vendor\Queryable\DB;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin donor endpoints: list, stats, insights, profile, timeline, notes,
 * profile edits, and the DSAR export / erasure pair.
 *
 * @since 1.0.0
 */
final class DonorsController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(
        private DonorRepository $donors,
        private DonorService $donorService,
        private DonorMetricsService $metrics,
        private DonorNoteRepository $notes,
        private DonationService $donationService,
        private \Dono\Donors\DonorAvatars $avatars,
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
                'donor_type' => ['type' => 'string', 'enum' => ['individual', 'organization', 'company', 'household']],
                'search'     => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'stats'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'country'    => ['type' => 'string'],
                'donor_type' => ['type' => 'string', 'enum' => ['individual', 'organization', 'company', 'household']],
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
            'permission_callback' => static fn () => Capabilities::userCan('dono_edit_donors'),
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
                        'country' => ['type' => 'string', 'pattern' => '^[A-Za-z]{2}$'],
                    ],
                ],
                'donor_type' => ['type' => 'string', 'enum' => ['individual', 'organization', 'household']],
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
            'permission_callback' => static fn () => Capabilities::userCan('dono_export_donors'),
        ]);

        // Minting a portal login is an action the admin takes, never something
        // a page load does on their behalf: the token impersonates the donor
        // for thirty days and cannot be revoked.
        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)/portal-link', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'issuePortalLink'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_edit_donors'),
            'args'                => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)/notes', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'createNote'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_edit_donors'),
            'args'                => [
                'id'   => ['type' => 'integer', 'required' => true],
                'body' => ['type' => 'string',  'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/notes/(?P<note_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'deleteNote'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_edit_donors'),
            'args'                => [
                'note_id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)/export', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'exportPersonalData'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_export_donors'),
            'args'                => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete'],
            // Erasing and deleting are both irreversible, so they answer to the
            // same capability.
            'permission_callback' => static fn () => Capabilities::userCan('dono_redact_donors'),
            'args'                => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/donors/(?P<id>\d+)/redact', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'redact'],
            'permission_callback' => static fn () => Capabilities::userCan('dono_redact_donors'),
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
        $payload = $this->metrics->profile((int) $request['id'], Capabilities::userCan('dono_edit_donors'));
        if (! $payload) {
            return new WP_Error('dono_not_found', __('Donor not found.', 'dono'), ['status' => 404]);
        }
        return new WP_REST_Response($payload, 200);
    }

    /** @since 1.0.0 */
    public function events(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->donors->findById((int) $request['id']);
        if (! $donor) {
            return new WP_Error('dono_not_found', __('Donor not found.', 'dono'), ['status' => 404]);
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
            return new WP_Error('dono_not_found', __('Donor not found.', 'dono'), ['status' => 404]);
        }
        // This handler writes name/company/country via a direct UPDATE and
        // phone/address via setEncryptedField, neither of which passes through
        // DonorService::editProfile's guard, so the whole edit is blocked here
        // or those writes would re-populate an erased row.
        if ($donor->redacted_at !== null) {
            return new WP_Error('dono_donor_redacted', __('This donor has been erased and can no longer be edited.', 'dono'), ['status' => 422]);
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
                $value = substr($value, 0, $maxLen);
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
            if (in_array($type, ['individual', 'organization', 'household'], true)) {
                $update['donor_type'] = $type;
            }
        }

        foreach ($update as $field => $value) {
            if ($donor->$field === $value) unset($update[$field]);
        }

        // One transaction so a partial failure can't leave mismatched
        // name / encrypted PII / hash. donor.updated fires after commit.
        $plainFieldsUpdated = false;

        try {
            DB::transaction(function () use ($donor, $params, $update, &$plainFieldsUpdated): void {
                if ($update) {
                    $update['updated_at'] = gmdate('Y-m-d H:i:s');
                    DB::table('dono_donors')->where('id', $donor->id)->update($update);
                    $plainFieldsUpdated = true;
                }

                if (array_key_exists('phone', $params)) {
                    $this->donorService->setEncryptedField($donor, 'phone_encrypted', is_string($params['phone']) ? trim($params['phone']) : '');
                }
                if (array_key_exists('address', $params)) {
                    $addr = is_array($params['address']) ? $params['address'] : null;
                    $this->donorService->setEncryptedField($donor, 'address_encrypted', $this->donorService->addressPayload($addr));
                }

                if (array_key_exists('email', $params) && is_string($params['email']) && trim($params['email']) !== '') {
                    $this->donorService->changeEmail($donor, trim($params['email']));
                }
            });
        } catch (EmailAlreadyAssignedException $e) {
            return new WP_Error(
                'dono_email_collision',
                /* translators: %d: donor id that already owns the requested email */
                sprintf(__('Another donor (#%d) already uses that email. Merge donors first if you want to consolidate them.', 'dono'), $e->existingDonorId),
                ['status' => 409, 'existing_donor_id' => $e->existingDonorId]
            );
        } catch (InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_email', $e->getMessage(), ['status' => 422]);
        }

        if ($plainFieldsUpdated) {
            do_action('dono.donor.updated', $this->donors->findById($donor->id));
        }

        return new WP_REST_Response($this->metrics->profile($donor->id, Capabilities::userCan('dono_edit_donors')), 200);
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
        $filename = 'dono-at-risk-' . gmdate('Y-m-d') . '.csv';
        $route    = $request->get_route();

        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($route, $csv, $filename) {
            if ((string) $req->get_route() !== $route) return $served;
            $server->send_header('Content-Type', 'text/csv; charset=utf-8');
            $server->send_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
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
            return new WP_Error('dono_donor_not_found', __('Donor not found.', 'dono'), ['status' => 404]);
        }

        $url = $this->metrics->issuePortalLink($donor);
        if ($url === null) {
            return new WP_Error(
                'dono_portal_link_unavailable',
                __('A sign-in link cannot be issued for an erased donor.', 'dono'),
                ['status' => 409]
            );
        }

        return new WP_REST_Response(['magic_link_url' => $url], 201);
    }
    public function createNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donorId = (int) $request['id'];
        if (! $this->donors->findById($donorId)) {
            return new WP_Error('dono_not_found', __('Donor not found.', 'dono'), ['status' => 404]);
        }
        $params = $request->get_json_params() ?: $request->get_body_params();
        $body   = trim((string) ($params['body'] ?? ''));
        if ($body === '') {
            return new WP_Error('dono_invalid', __('Note body is required.', 'dono'), ['status' => 400]);
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
            return new WP_Error('dono_not_found', __('Note not found.', 'dono'), ['status' => 404]);
        }
        if ($note->author_user_id && $note->author_user_id !== get_current_user_id() && ! current_user_can('manage_options')) {
            return new WP_Error('dono_forbidden', __('You cannot delete this note.', 'dono'), ['status' => 403]);
        }
        $this->notes->delete($noteId);
        return new WP_REST_Response(['deleted' => true], 200);
    }

    /**
     * GDPR/DSAR export, decrypting PII on the way out.
     *
     * @since 1.0.0
     */
    public function exportPersonalData(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->donors->findById((int) $request['id']);
        if (! $donor) {
            return new WP_Error('dono_not_found', __('Donor not found.', 'dono'), ['status' => 404]);
        }

        $data = $this->metrics->exportData($donor->id);
        if ($data === null) {
            return new WP_Error('dono_not_found', __('Donor not found.', 'dono'), ['status' => 404]);
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
        $filename = sprintf('dono-donor-%d-%s.json', $donor->id, gmdate('Y-m-d'));
        $route    = $request->get_route();

        add_filter('rest_pre_serve_request', function (bool $served, $result, $req, $server) use ($route, $json, $filename) {
            if ((string) $req->get_route() !== $route) return $served;

            $server->send_header('Content-Type', 'application/json; charset=utf-8');
            $server->send_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $server->send_header('Cache-Control', 'private, no-cache, no-store, must-revalidate');

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
     * No confirmation string, unlike redact: a donor who reaches here has
     * nothing to lose, and one with donations is refused with the path to take
     * instead.
     *
     * @since 1.0.0
     */
    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $donor = $this->donors->findById((int) $request['id']);
        if (! $donor) {
            return new WP_Error('dono_not_found', __('Donor not found.', 'dono'), ['status' => 404]);
        }

        $reason = $this->donorService->undeletableReason($donor);
        if ($reason !== null) {
            return new WP_Error('dono_donor_not_deletable', $reason, ['status' => 409]);
        }

        $this->donorService->delete($donor);

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
            return new WP_Error('dono_not_found', __('Donor not found.', 'dono'), ['status' => 404]);
        }
        if ($donor->redacted_at !== null) {
            return new WP_Error('dono_already_redacted', __('This donor is already redacted.', 'dono'), ['status' => 409]);
        }

        $params = $request->get_json_params() ?: $request->get_body_params() ?: [];
        $confirmation = trim((string) ($params['confirmation'] ?? ''));

        $expected = $this->donorService->decryptEmail($donor) ?: sprintf('DONOR_%d', $donor->id);
        if ($confirmation === '' || strcasecmp($confirmation, $expected) !== 0) {
            return new WP_Error(
                'dono_confirmation_mismatch',
                __('Confirmation does not match the donor email. Redact cancelled.', 'dono'),
                ['status' => 422],
            );
        }

        $this->donorService->redact($donor);

        return new WP_REST_Response([
            'redacted'    => true,
            'redacted_at' => $donor->redacted_at,
            'public_hidden' => $donor->public_hidden_at !== null,
            'avatar_url'    => $this->avatars->adminUrl($donor),
        ], 200);
    }

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_view_donors');
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

        $shaped = array_map(
            fn (Donor $d): array => [
                'id'                  => $d->id,
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
    private function donorName(Donor $d): string
    {
        $full = trim(($d->first_name ?? '') . ' ' . ($d->last_name ?? ''));
        return $full !== '' ? $full : '-';
    }
}
