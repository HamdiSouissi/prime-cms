<?php

declare(strict_types=1);

namespace Prime\Api\Controller;

use Prime\Auth\User;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Media\Exception\MediaException;
use Prime\Media\Exception\MediaValidationException;
use Prime\Media\Media;
use Prime\Media\MediaConfig;
use Prime\Media\MediaRepository;
use Prime\Media\MediaUsageRepository;
use Prime\Media\UploadService;
use Prime\Site\UrlGenerator;

/**
 * Media library of the REST API.
 *
 * Uploads go through UploadService, the same path as the administration: type and size
 * checks, SVG refused, images re-encoded without metadata. A media in use cannot be
 * deleted unless `force=1` is sent by someone allowed to delete any media.
 */
final class MediaController extends ApiController
{
    private const KINDS = ['image', 'document', 'audio', 'video', 'archive'];

    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaUsageRepository $usages,
        private readonly UploadService $uploader,
        private readonly MediaConfig $config,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'page' => max(1, (int) $request->query('page', 1)),
            'per_page' => max(1, min(100, (int) $request->query('per_page', 24))),
        ];

        $kind = (string) $request->query('kind', '');
        if ($kind !== '') {
            if (!in_array($kind, self::KINDS, true)) {
                return $this->failure($request, 'invalid_request', 400);
            }
            $filters['kind'] = $kind;
        }

        $folder = (string) $request->query('folder', '');
        if ($folder !== '' && ctype_digit($folder)) {
            $filters['folder_id'] = (int) $folder;
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $filters['q'] = $search;
        }

        $result = $this->media->paginate($filters);
        $user = $this->currentUser($request);

        return $this->json([
            'data' => array_map(fn (Media $item): array => $this->resource($item, $user), $result['items']),
            'meta' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'total_pages' => $result['total_pages'],
            ],
        ], 200, ['X-Total-Count' => (string) $result['total']]);
    }

    public function show(Request $request, string $id): Response
    {
        $item = $this->media->find((int) $id);
        if ($item === null) {
            return $this->failure($request, 'not_found', 404);
        }

        $data = $this->resource($item, $this->currentUser($request));
        $data['usages'] = $this->usages->findByMedia($item->id);

        return $this->json(['data' => $data]);
    }

    public function store(Request $request): Response
    {
        $user = $this->currentUser($request);
        $file = $request->file('file');
        if ($user === null) {
            return $this->failure($request, 'unauthenticated', 401);
        }
        if (!is_array($file)) {
            return $this->validationFailed($request, ['file' => t('api.validation.file_missing')]);
        }

        $body = $this->body($request);
        $folder = $body['folder_id'] ?? null;

        try {
            $result = $this->uploader->upload(
                $file,
                $user,
                is_scalar($folder) && ctype_digit((string) $folder) && (int) $folder > 0 ? (int) $folder : null,
                [
                    'title' => is_string($body['title'] ?? null) ? $body['title'] : '',
                    'alt' => is_string($body['alt'] ?? null) ? $body['alt'] : '',
                ],
            );
        } catch (MediaValidationException $exception) {
            return $this->validationFailed($request, $exception->errors() !== [] ? $exception->errors() : ['file' => $exception->getMessage()]);
        } catch (MediaException) {
            return $this->failure($request, 'server_error', 500);
        }

        $media = $result['media'];
        $data = $this->resource($media, $user);
        $data['duplicate_of'] = $result['duplicate']?->id;

        return $this->json(['data' => $data], 201, ['Location' => $this->urls->absolute('/api/v1/media/' . $media->id)]);
    }

    public function update(Request $request, string $id): Response
    {
        $user = $this->currentUser($request);
        $item = $this->media->find((int) $id);
        if ($user === null) {
            return $this->failure($request, 'unauthenticated', 401);
        }
        if ($item === null) {
            return $this->failure($request, 'not_found', 404);
        }
        if (!$this->canEdit($user, $item)) {
            return $this->failure($request, 'forbidden', 403);
        }

        $input = array_intersect_key($this->body($request), array_flip(['title', 'alt', 'caption', 'description', 'folder_id', 'focal_x', 'focal_y']));

        try {
            $updated = $this->uploader->updateMetadata($item, $input, $user);
        } catch (MediaValidationException $exception) {
            return $this->validationFailed($request, $exception->errors() !== [] ? $exception->errors() : ['media' => $exception->getMessage()]);
        } catch (MediaException) {
            return $this->failure($request, 'server_error', 500);
        }

        return $this->json(['data' => $this->resource($updated, $user)]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $user = $this->currentUser($request);
        $item = $this->media->find((int) $id);
        if ($user === null) {
            return $this->failure($request, 'unauthenticated', 401);
        }
        if ($item === null) {
            return $this->failure($request, 'not_found', 404);
        }
        if (!$this->canEdit($user, $item)) {
            return $this->failure($request, 'forbidden', 403);
        }

        $force = in_array((string) $request->query('force', ''), ['1', 'true'], true);
        if ($this->usages->findByMedia($item->id) !== [] && !($force && $user->can('media.delete_any'))) {
            return $this->failure($request, 'conflict', 409);
        }

        $this->uploader->delete($item, $user);

        return $this->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Media $media, ?User $viewer): array
    {
        $data = $media->toApiArray($this->config->urlPrefix());
        unset($data['disk_path'], $data['checksum'], $data['focal_css'], $data['author_name']);

        foreach (['url', 'thumbnail_url', 'medium_url'] as $key) {
            if (is_string($data[$key] ?? null) && $data[$key] !== '') {
                $data[$key] = $this->urls->absolute($data[$key]);
            }
        }

        $data['_links'] = [
            'self' => $this->urls->absolute('/api/v1/media/' . $media->id),
            'collection' => $this->urls->absolute('/api/v1/media'),
        ];
        if ($viewer !== null && $this->canEdit($viewer, $media)) {
            $data['_links']['edit_url'] = $this->urls->absolute('/admin/media?open=' . $media->id);
        }

        return $data;
    }

    /**
     * Same rule as the media library screen: any media with `media.delete_any`, otherwise
     * only one's own uploads.
     */
    private function canEdit(User $user, Media $media): bool
    {
        if ($user->can('media.delete_any')) {
            return true;
        }

        return $user->can('media.upload') && $media->authorId !== null && $media->authorId === $user->id;
    }
}
