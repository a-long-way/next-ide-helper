<?php

namespace Soyhuce\NextIdeHelper\Domain\Models\Output;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Soyhuce\NextIdeHelper\Contracts\Renderer;
use Soyhuce\NextIdeHelper\Domain\Models\Entities\Attribute;
use Soyhuce\NextIdeHelper\Domain\Models\Entities\Model;
use Soyhuce\NextIdeHelper\Domain\Models\Entities\Relation;
use Soyhuce\NextIdeHelper\Support\Output\DocBlock;
use function in_array;

class ModelDocBlock extends DocBlock implements Renderer
{
    private Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function render(): void
    {
        $this->replacePreviousBlock(
            $this->docblock(),
            $this->model->fqcn,
            $this->model->filePath
        );
    }

    protected function docblock(): string
    {
        return Collection::make([
            '/**',
            $this->properties(),
            $this->propertiesRead(),
            $this->propertiesWrite(),
            $this->relations(),
            $this->all(),
            $this->query(),
            $this->phpstanMethods(),
            $this->queryMixin(),
            $this->factory(),
            ' */',
        ])
            ->map(fn (?string $line): string => $this->line($line))
            ->implode('');
    }

    private function properties(): string
    {
        return $this->model->attributes
            ->onlyReadOnly(false)
            ->onlyWriteOnly(false)
            ->map(fn (Attribute $attribute) => ' * @property ' . $this->propertyLine($attribute))
            ->implode(PHP_EOL);
    }

    private function propertiesRead(): string
    {
        return $this->model->attributes
            ->onlyReadOnly(true)
            ->onlyWriteOnly(false)
            ->map(fn (Attribute $attribute) => ' * @property-read ' . $this->propertyLine($attribute))
            ->implode(PHP_EOL);
    }

    private function propertiesWrite(): string
    {
        return $this->model->attributes
            ->onlyReadOnly(false)
            ->onlyWriteOnly(true)
            ->map(fn (Attribute $attribute) => ' * @property-write ' . $this->propertyLine($attribute))
            ->implode(PHP_EOL);
    }

    private function propertyLine(Attribute $attribute): string
    {
        $line = sprintf('%s $%s', $attribute->toUnionType(), $attribute->name);
        if ($attribute->comment !== null) {
            $line .= " {$attribute->comment}";
        }

        return $line;
    }

    private function relations(): string
    {
        return $this->model->relations
            ->sortBy('name')
            ->map(static fn (Relation $relation) => " * @property-read {$relation->returnType()} \${$relation->name}")
            ->implode(PHP_EOL);
    }

    private function query(): ?string
    {
        if ($this->model->queryBuilder->isBuiltIn()) {
            return null;
        }

        return " * @method static {$this->model->queryBuilder->fqcn} query()";
    }

    private function all(): ?string
    {
        if ($this->model->collection->isBuiltIn()) {
            return null;
        }

        return " * @method static {$this->model->collection->fqcn} all(array|mixed \$columns = ['*'])";
    }

    private function phpstanMethods(): ?string
    {
        if (!config('next-ide-helper.models.larastan_friendly', false)) {
            return null;
        }

        if ($this->model->collection->isBuiltIn()) {
            return null;
        }

        return " * @phpstan-method static {$this->model->collection->fqcn}<int, {$this->model->fqcn}> all(array|mixed \$columns = ['*'])";
    }

    private function queryMixin(): ?string
    {
        if ($this->model->queryBuilder->isBuiltIn()) {
            return null;
        }

        $mixin = " * @mixin {$this->model->queryBuilder->fqcn}";

        if (config('next-ide-helper.models.larastan_friendly', false)) {
            $mixin .= "<{$this->model->fqcn}>";
        }

        return $mixin;
    }

    private function factory(): ?string
    {
        if (!trait_exists(\Illuminate\Database\Eloquent\Factories\HasFactory::class)) {
            return null;
        }

        if (!in_array(
            \Illuminate\Database\Eloquent\Factories\HasFactory::class,
            class_uses_recursive($this->model->fqcn)
        )) {
            return null;
        }

        $factory = Str::start($this->model->fqcn::factory()::class, '\\');

        return " * @method static {$factory} factory(\$count = 1, \$state = [])";
    }
}
