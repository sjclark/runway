<?php

namespace StatamicRadPack\Runway\Http\Controllers\CP;

use Statamic\CP\Breadcrumbs;
use Statamic\CP\Column;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Action;
use Statamic\Facades\Scope;
use Statamic\Facades\User;
use Statamic\Fields\Field;
use Statamic\Http\Controllers\CP\CpController;
use StatamicRadPack\Runway\Fieldtypes\HasManyFieldtype;
use StatamicRadPack\Runway\Http\Requests\CP\CreateRequest;
use StatamicRadPack\Runway\Http\Requests\CP\EditRequest;
use StatamicRadPack\Runway\Http\Requests\CP\IndexRequest;
use StatamicRadPack\Runway\Http\Requests\CP\StoreRequest;
use StatamicRadPack\Runway\Http\Requests\CP\UpdateRequest;
use StatamicRadPack\Runway\Http\Resources\CP\Model as ModelResource;
use StatamicRadPack\Runway\Resource;

class ResourceController extends CpController
{
    use Traits\ExtractsFromModelFields, Traits\HasListingColumns, Traits\PreparesModels;

    public function index(IndexRequest $request, Resource $resource)
    {
        $listingConfig = [
            'preferencesPrefix' => "runway.{$resource->handle()}",
            'requestUrl' => cp_route('runway.listing-api', ['resource' => $resource->handle()]),
            'listingUrl' => cp_route('runway.index', ['resource' => $resource->handle()]),
        ];

        return view('runway::index', [
            'title' => $resource->name(),
            'resource' => $resource,
            'modelCount' => $resource->model()->count(),
            'primaryColumn' => $this->getPrimaryColumn($resource),
            'columns' => $resource->blueprint()->columns()
                ->when($resource->hasPublishStates(), function ($collection) {
                    $collection->put('status', Column::make('status')
                        ->listable(true)
                        ->visible(true)
                        ->defaultVisibility(true)
                        ->sortable(false));
                })
                ->setPreferred("runway.{$resource->handle()}.columns")
                ->rejectUnlisted()
                ->values(),
            'filters' => Scope::filters('runway', ['resource' => $resource->handle()]),
            'listingConfig' => $listingConfig,
            'actionUrl' => cp_route('runway.actions.run', ['resource' => $resource->handle()]),
            'hasPublishStates' => $resource->hasPublishStates(),
            'canCreate' => User::current()->can('create', $resource)
                && $resource->hasVisibleBlueprint()
                && ! $resource->readOnly(),
        ]);
    }

    public function create(CreateRequest $request, Resource $resource)
    {
        $blueprint = $resource->blueprint();
        $fields = $blueprint->fields();
        $fields = $fields->preProcess();

        $viewData = [
            'title' => __('Create :resource', ['resource' => $resource->singular()]),
            'breadcrumbs' => new Breadcrumbs([[
                'text' => $resource->plural(),
                'url' => cp_route('runway.index', [
                    'resource' => $resource->handle(),
                ]),
            ]]),
            'actions' => [
                'save' => cp_route('runway.store', ['resource' => $resource->handle()]),
            ],
            'resource' => $request->wantsJson() ? $resource->toArray() : $resource,
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values()->merge([
                $resource->publishedColumn() => true,
            ])->all(),
            'meta' => $fields->meta(),
            'resourceHasRoutes' => $resource->hasRouting(),
            'canManagePublishState' => User::current()->can('edit', $resource),
        ];

        if ($request->wantsJson()) {
            return $viewData;
        }

        return view('runway::create', $viewData);
    }

    public function store(StoreRequest $request, Resource $resource)
    {
        $resource
            ->blueprint()
            ->fields()
            ->addValues($request->all())
            ->validator()
            ->validate();

        $model = $resource->model();

        $postCreatedHooks = $resource->blueprint()->fields()->all()
            ->filter(fn (Field $field) => $field->fieldtype() instanceof HasManyFieldtype)
            ->map(fn (Field $field) => $field->fieldtype()->process($request->get($field->handle())))
            ->values();

        $this->prepareModelForSaving($resource, $model, $request);

        if ($resource->revisionsEnabled()) {
            $saved = $model->store([
                'message' => $request->message,
                'user' => User::current(),
            ]);
        } else {
            $saved = $model->save();
        }

        // Runs anything in the $postCreatedHooks array. See HasManyFieldtype@process for an example
        // of where this is used.
        $postCreatedHooks->each(fn ($postCreatedHook) => $postCreatedHook($resource, $model));

        return [
            'data' => (new ModelResource($model->fresh()))->resolve()['data'],
            'saved' => $saved,
        ];
    }

    public function edit(EditRequest $request, Resource $resource, $model)
    {
        $model = $resource->model()->where($resource->model()->qualifyColumn($resource->routeKey()), $model)->first();

        if (! $model) {
            throw new NotFoundHttpException();
        }

        $model = $model->fromWorkingCopy();

        $blueprint = $resource->blueprint();

        [$values, $meta] = $this->extractFromFields($model, $resource, $blueprint);

        $viewData = [
            'title' => $model->getAttribute($resource->titleField()),
            'reference' => $model->reference(),
            'method' => 'patch',
            'breadcrumbs' => new Breadcrumbs([[
                'text' => $resource->plural(),
                'url' => cp_route('runway.index', [
                    'resource' => $resource->handle(),
                ]),
            ]]),
            'resource' => $resource,
            'actions' => [
                'save' => $model->runwayUpdateUrl(),
                'publish' => $model->runwayPublishUrl(),
                'unpublish' => $model->runwayUnpublishUrl(),
                'revisions' => $model->runwayRevisionsUrl(),
                'restore' => $model->runwayRestoreRevisionUrl(),
                'createRevision' => $model->runwayCreateRevisionUrl(),
                'editBlueprint' => cp_route('blueprints.edit', ['namespace' => 'runway', 'handle' => $resource->handle()]),
            ],
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $values,
            'meta' => $meta,
            'readOnly' => $resource->readOnly(),
            'permalink' => $resource->hasRouting() ? $model->uri() : null,
            'resourceHasRoutes' => $resource->hasRouting(),
            'currentModel' => [
                'id' => $model->getKey(),
                'reference' => $model->reference(),
                'title' => $model->{$resource->titleField()},
                'edit_url' => $request->url(),
            ],
            'canManagePublishState' => User::current()->can('edit', $resource),
            'itemActions' => Action::for($model, ['resource' => $resource->handle(), 'view' => 'form']),
            'revisionsEnabled' => $resource->revisionsEnabled(),
        ];

        if ($request->wantsJson()) {
            return $viewData;
        }

        return view('runway::edit', $viewData);
    }

    public function update(UpdateRequest $request, Resource $resource, $model)
    {
        $resource->blueprint()->fields()->setParent($model)->addValues($request->all())->validator()->validate();

        $model = $resource->model()->where($resource->model()->qualifyColumn($resource->routeKey()), $model)->first();
        $model = $model->fromWorkingCopy();

        $this->prepareModelForSaving($resource, $model, $request);

        if ($resource->revisionsEnabled() && $model->published()) {
            $saved = $model
                ->makeWorkingCopy()
                ->user(User::current())
                ->save();

            $model = $model->fromWorkingCopy();
        } else {
            $saved = $model->save();
        }

        [$values] = $this->extractFromFields($model, $resource, $resource->blueprint());

        return [
            'data' => array_merge((new ModelResource($model->fresh()))->resolve()['data'], [
                'values' => $values,
            ]),
            'saved' => $saved,
        ];
    }
}
