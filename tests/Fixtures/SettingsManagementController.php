<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests\Fixtures;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Enums\SettingAbility;
use Nvl\Settings\Exceptions\SettingException;
use Nvl\Settings\Exceptions\StaleSettingVersionException;
use Nvl\Settings\Exceptions\UnknownSettingException;
use Nvl\Settings\Services\SettingReader;
use Nvl\Settings\Services\SettingsSourceValidator;
use Nvl\Settings\Services\SettingWriter;

/** Consumer-owned HTTP fixture for exercising transport mapping and errors. */
final class SettingsManagementController extends Controller
{
    public function __construct(private readonly SettingsAuthorization $authorization) {}

    public function status(Request $request, SettingsSourceValidator $validator): JsonResponse
    {
        $this->authorization->authorize($request->user() ?? abort(401), SettingAbility::Status);
        try {
            $status = $validator->inspect();
        } catch (SettingException) {
            $status = ['valid' => false, 'source_count' => 0, 'definition_count' => 0,
                'namespaces' => [], 'source_files' => [], 'checksum' => null,
                'error' => 'One or more setting definition sources are invalid.'];
        }

        return response()->json(['data' => $this->camel($status)]);
    }

    public function index(Request $request, SettingReader $reader): JsonResponse
    {
        $this->authorization->authorize($request->user() ?? abort(401), SettingAbility::List);
        $query = $request->query();
        if (array_key_exists('perPage', $query)) {
            $query['per_page'] = $query['perPage'];
            unset($query['perPage']);
        }
        $page = $reader->paginate($query);

        return response()->json(['data' => [
            'items' => $this->camel($page->items()),
            'meta' => ['currentPage' => $page->currentPage(), 'perPage' => $page->perPage(),
                'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]]);
    }

    public function show(string $key, Request $request, SettingReader $reader): JsonResponse
    {
        $this->authorization->authorize($request->user() ?? abort(401), SettingAbility::View, $key);
        try {
            return response()->json(['data' => $this->camel($reader->get($key))]);
        } catch (UnknownSettingException $exception) {
            return $this->error('unknown_setting', $exception->getMessage(), 404);
        }
    }

    public function update(string $key, Request $request, SettingWriter $writer): JsonResponse
    {
        $input = $request->validate([
            'value' => ['present'], 'expectedRevision' => ['required', 'integer', 'min:0'],
            'validFrom' => ['sometimes', 'nullable', 'date'], 'validUntil' => ['sometimes', 'nullable', 'date'],
        ]);
        $options = [];
        if (array_key_exists('validFrom', $input)) {
            $options['valid_from'] = $input['validFrom'];
        }
        if (array_key_exists('validUntil', $input)) {
            $options['valid_until'] = $input['validUntil'];
        }
        try {
            $result = $writer->set($key, $input['value'], $request->user() ?? abort(401), (int) $input['expectedRevision'], $options);

            return response()->json(['data' => $this->camel($result)]);
        } catch (UnknownSettingException $exception) {
            return $this->error('unknown_setting', $exception->getMessage(), 404);
        } catch (StaleSettingVersionException $exception) {
            return $this->error('stale_setting_revision', $exception->getMessage(), 409);
        }
    }

    public function reset(string $key, Request $request, SettingWriter $writer): JsonResponse
    {
        $input = $request->validate(['expectedRevision' => ['required', 'integer', 'min:1']]);
        try {
            $result = $writer->reset($key, $request->user() ?? abort(401), (int) $input['expectedRevision']);

            return response()->json(['data' => $this->camel($result)]);
        } catch (UnknownSettingException $exception) {
            return $this->error('unknown_setting', $exception->getMessage(), 404);
        } catch (StaleSettingVersionException $exception) {
            return $this->error('stale_setting_revision', $exception->getMessage(), 409);
        } catch (ModelNotFoundException) {
            return $this->error('setting_override_not_found', "Setting [{$key}] has not been synchronized or overridden.", 404);
        }
    }

    /** @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function camel(array $data): array
    {
        $mapped = [];
        foreach ($data as $key => $value) {
            $mapped[is_string($key) ? Str::camel($key) : $key] = is_array($value) ? $this->camel($value) : $value;
        }

        return $mapped;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
