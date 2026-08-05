<?php

declare(strict_types=1);

namespace Nvl\Settings\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nvl\Settings\Actions\GetSettingAction;
use Nvl\Settings\Actions\ListSettingsAction;
use Nvl\Settings\Actions\ResetSettingAction;
use Nvl\Settings\Actions\SetSettingAction;
use Nvl\Settings\Actions\ValidateSettingsSourcesAction;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Data\Mutations\ExpectedRevisionData;
use Nvl\Settings\Data\SettingListQueryData;
use Nvl\Settings\Data\SettingMutationData;
use Nvl\Settings\Data\SettingsSourceStatusData;
use Nvl\Settings\Enums\SettingAbility;
use Nvl\Settings\Exceptions\SettingException;
use Nvl\Settings\Exceptions\StaleSettingVersionException;
use Nvl\Settings\Exceptions\UnknownSettingException;

/**
 * Thin HTTP adapter for the optional settings management surface.
 */
final class SettingsManagementController extends Controller
{
    /**
     * Create the authorized management HTTP adapter.
     */
    public function __construct(
        private readonly SettingsAuthorization $authorization,
    ) {}

    /**
     * Return sanitized source-discovery and validation status.
     */
    public function status(ValidateSettingsSourcesAction $action): JsonResponse
    {
        $this->authorization->authorize(SettingAbility::Status);

        try {
            $status = $action->execute();
        } catch (SettingException) {
            $status = new SettingsSourceStatusData(
                valid: false,
                sourceCount: 0,
                definitionCount: 0,
                namespaces: [],
                sourceFiles: [],
                checksum: null,
                error: 'One or more setting definition sources are invalid.',
            );
        }

        return response()->json(['data' => $status->toArray()]);
    }

    /**
     * Return every source definition and its effective value.
     */
    public function index(Request $request, ListSettingsAction $action): JsonResponse
    {
        $this->authorization->authorize(SettingAbility::List);
        $query = SettingListQueryData::validateAndCreate($request->query());

        return response()->json([
            'data' => $action->execute($query)->toArray(),
        ]);
    }

    /**
     * Return one effective setting value.
     */
    public function show(string $key, GetSettingAction $action): JsonResponse
    {
        $this->authorization->authorize(SettingAbility::View, $key);

        try {
            return response()->json(['data' => $action->execute($key)->toArray()]);
        } catch (UnknownSettingException $exception) {
            return $this->error(
                'unknown_setting',
                $exception->getMessage(),
                404,
            );
        }
    }

    /**
     * Validate and persist one optimistic runtime override.
     */
    public function update(string $key, Request $request, SetSettingAction $action): JsonResponse
    {
        $this->authorization->authorize(SettingAbility::Set, $key);
        $data = SettingMutationData::validateAndCreate(array_replace(
            $request->all(),
            ['key' => $key],
        ));

        try {
            return response()->json(['data' => $action->execute($data)->toArray()]);
        } catch (UnknownSettingException $exception) {
            return $this->error('unknown_setting', $exception->getMessage(), 404);
        } catch (StaleSettingVersionException $exception) {
            return $this->error('stale_setting_revision', $exception->getMessage(), 409);
        }
    }

    /**
     * Clear one override using optimistic concurrency.
     */
    public function reset(string $key, Request $request, ResetSettingAction $action): JsonResponse
    {
        $this->authorization->authorize(SettingAbility::Reset, $key);
        $data = ExpectedRevisionData::validateAndCreate($request->all());

        try {
            return response()->json([
                'data' => $action->execute($key, $data->expectedRevision)->toArray(),
            ]);
        } catch (UnknownSettingException $exception) {
            return $this->error('unknown_setting', $exception->getMessage(), 404);
        } catch (StaleSettingVersionException $exception) {
            return $this->error('stale_setting_revision', $exception->getMessage(), 409);
        } catch (ModelNotFoundException) {
            return $this->error(
                'setting_override_not_found',
                "Setting [{$key}] has not been synchronized or overridden.",
                404,
            );
        }
    }

    /**
     * Return one stable management API error envelope.
     */
    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
