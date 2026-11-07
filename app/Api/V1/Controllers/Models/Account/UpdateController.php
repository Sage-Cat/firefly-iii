<?php

/**
 * AccountController.php
 * Copyright (c) 2019 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\Models\Account;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Models\Account\UpdateRequest;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\JsonApi\Enrichments\AccountEnrichment;
use FireflyIII\Transformers\AccountTransformer;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use League\Fractal\Resource\Item;

/**
 * Class UpdateController
 */
final class UpdateController extends Controller
{
    public const string RESOURCE_KEY = 'accounts';

    private AccountRepositoryInterface $repository;

    /**
     * AccountController constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            $this->repository = app(AccountRepositoryInterface::class);
            $this->repository->setUser(auth()->user());

            return $next($request);
        });
    }

    /**
     * This endpoint is documented at:
     * https://api-docs.firefly-iii.org/?urls.primaryName=2.0.0%20(v1)#/accounts/updateAccount
     *
     * Update account.
     */
    public function update(UpdateRequest $request, Account $account): JsonResponse
    {
        if ($request->headers->has('If-Match-Notes')) {
            $expected = $request->header('If-Match-Notes');
            if (!is_string($expected) || 1 !== preg_match('/^[0-9a-f]{64}$/D', $expected)) {
                return response()->json(['message' => 'Invalid account notes hash.'], 422);
            }
            if ((string) $account->user_group_id !== (string) auth()->user()->user_group_id) {
                return response()->json(['message' => 'Account not found.'], 404);
            }
            $request->headers->remove('If-Match-Notes');

            return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $account, $expected): JsonResponse {
                $notes = $account->notes()->lockForUpdate()->get();
                if (1 !== $notes->count()) {
                    return response()->json(['message' => 'Conditional notes update requires one existing note.'], 409);
                }
                $current = (string) ($notes->first()->text ?? '');
                if (!hash_equals($expected, hash('sha256', $current))) {
                    return response()->json(['message' => 'Account notes changed since they were read.'], 409);
                }

                $data = $request->getUpdateData();
                if (array_keys($request->all()) !== ['notes'] || !array_key_exists('notes', $data)) {
                    return response()->json(['message' => 'Conditional notes updates accept only notes.'], 422);
                }
                $note = $notes->first();
                $text = trim((string) $data['notes']);
                if ('' === $text) {
                    $note->delete();
                } else {
                    $note->text = $text;
                    $note->save();
                }

                return response()->json(['data' => ['type' => 'accounts', 'id' => (string) $account->id, 'attributes' => ['notes' => $text]]])->header(
                    'Content-Type',
                    self::CONTENT_TYPE
                );
            });
        }

        Log::debug(sprintf('Now in %s', __METHOD__));
        $data         = $request->getUpdateData();
        $data['type'] = config('firefly.shortNamesByFullName.' . $account->accountType->type);
        $account      = $this->repository->update($account, $data);
        $manager      = $this->getManager();
        $account->refresh();
        Preferences::mark();

        // enrich
        /** @var User $admin */
        $admin      = auth()->user();
        $enrichment = new AccountEnrichment();
        $enrichment->setDate(null);
        $enrichment->setUser($admin);
        $account = $enrichment->enrichSingle($account);

        /** @var AccountTransformer $transformer */
        $transformer = app(AccountTransformer::class);
        $resource    = new Item($account, $transformer, self::RESOURCE_KEY);

        return response()->json($manager->createData($resource)->toArray())->header('Content-Type', self::CONTENT_TYPE);
    }
}
