<?php

declare(strict_types=1);

namespace Fhferreira\LlmsTxt\Generator\Inspectors;

use Fhferreira\LlmsTxt\Generator\RulesToJsonSchema;
use Illuminate\Routing\Route;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Scan a controller method body for inline validation calls and lift the rules
 * into the endpoint's parameter list.
 *
 * Recognises:
 *   - `$request->validate([...])`
 *   - `$req->validate([...])`            (any var hinted as Request)
 *   - `request()->validate([...])`
 *   - `Validator::make($..., [...])`
 *   - `Validator::make($..., [...])->validate()`
 *
 * Only literal `Array_` rule expressions are extracted; dynamically-built rule
 * arrays (variables, function calls, etc.) are silently skipped so the
 * generator never crashes on edge cases.
 */
final class InlineValidationInspector
{
    public function apply(array &$record, Route $route): void
    {
        $action = $route->getAction('uses');
        if (! is_string($action) || ! str_contains($action, '@')) {
            return;
        }

        [$class, $method] = explode('@', $action);
        if (! class_exists($class)) {
            return;
        }

        try {
            $refMethod = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return;
        }

        $source = $this->extractMethodSource($refMethod);
        if ($source === null) {
            return;
        }

        $rules = $this->findValidationRules($source);
        if ($rules === []) {
            return;
        }

        $bucket = in_array($record['method'] ?? 'GET', ['GET', 'DELETE', 'HEAD'], true) ? 'query' : 'body';

        $existing = collect($record['parameters'] ?? [])->keyBy(fn ($p) => $p['in'] . ':' . $p['name']);

        foreach ($rules as $field => $ruleList) {
            // Don't clobber path parameters or anything the FormRequest /
            // overlay inspectors already wrote — those are authoritative.
            $key = $bucket . ':' . $field;
            if ($existing->has($key)) {
                continue;
            }
            if ($existing->has('path:' . $field)) {
                continue;
            }

            $required = in_array('required', $ruleList, true);

            $existing[$key] = [
                'name'        => (string) $field,
                'in'          => $bucket,
                'required'    => $required,
                'type'        => $this->inferType($ruleList),
                'description' => $this->summarize($ruleList),
                'example'     => null,
                'rules'       => $ruleList,
            ];
        }

        $record['parameters'] = $existing->values()->all();
    }

    /**
     * Pull just the source of `$method` from its containing file.
     */
    private function extractMethodSource(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if ($file === false || $start === false || $end === false) {
            return null;
        }
        $lines = @file($file);
        if ($lines === false) {
            return null;
        }
        // Wrap the slice in a synthetic class so PhpParser can parse a fragment.
        $slice = implode('', array_slice($lines, $start - 1, $end - $start + 1));
        return "<?php\nclass __LlmsScan__ {\n" . $slice . "\n}\n";
    }

    /**
     * @return array<string, array<int, string>>  field => ruleList
     */
    private function findValidationRules(string $source): array
    {
        try {
            $parser = (new ParserFactory())->createForHostVersion();
            $ast = $parser->parse($source);
        } catch (Throwable) {
            return [];
        }
        if ($ast === null) {
            return [];
        }

        $finder = new NodeFinder();
        /** @var array<int, MethodCall|StaticCall> $candidates */
        $candidates = $finder->find($ast, function (Node $node): bool {
            if ($node instanceof MethodCall) {
                return $node->name instanceof Identifier && $node->name->toString() === 'validate';
            }
            if ($node instanceof StaticCall) {
                return $node->class instanceof Name
                    && $node->name instanceof Identifier
                    && in_array($node->name->toString(), ['make', 'validate'], true)
                    && in_array($node->class->getLast(), ['Validator'], true);
            }
            return false;
        });

        $out = [];
        foreach ($candidates as $call) {
            $rulesArg = $this->rulesArg($call);
            if (! $rulesArg instanceof Array_) {
                continue;
            }
            foreach ($rulesArg->items as $item) {
                if ($item === null || $item->key === null) {
                    continue;
                }
                $field = $this->literalString($item->key);
                if ($field === null) {
                    continue;
                }
                $ruleList = $this->literalRuleList($item->value);
                if ($ruleList === []) {
                    continue;
                }
                // First win — same key from a later call is treated as duplicate.
                if (! array_key_exists($field, $out)) {
                    $out[$field] = $ruleList;
                }
            }
        }
        return $out;
    }

    private function rulesArg(MethodCall|StaticCall $call): ?Node
    {
        // `$request->validate([...])` → args[0] is the rules array
        if ($call instanceof MethodCall) {
            return $call->args[0]->value ?? null;
        }
        // `Validator::make($data, [...])` → args[1]
        // `Validator::validate($data, [...])` → args[1]
        return $call->args[1]->value ?? null;
    }

    private function literalString(Node $node): ?string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }
        return null;
    }

    /**
     * Accept either `'required|string|max:255'` or `['required','string','max:255']`.
     *
     * @return array<int, string>
     */
    private function literalRuleList(Node $node): array
    {
        if ($node instanceof Node\Scalar\String_) {
            return array_values(array_filter(array_map('trim', explode('|', $node->value))));
        }
        if ($node instanceof Array_) {
            $out = [];
            foreach ($node->items as $item) {
                if ($item === null) {
                    continue;
                }
                if ($item->value instanceof Node\Scalar\String_) {
                    $out[] = $item->value->value;
                }
                // Rule objects (`Rule::in(...)`) and other dynamic entries: skip silently.
            }
            return $out;
        }
        return [];
    }

    private function inferType(array $rules): string
    {
        foreach (['integer', 'numeric', 'boolean', 'array', 'string', 'email', 'url', 'uuid', 'date'] as $hint) {
            if (in_array($hint, $rules, true)) {
                return match ($hint) {
                    'integer'           => 'integer',
                    'numeric'           => 'number',
                    'boolean'           => 'boolean',
                    'array'             => 'array',
                    default             => 'string',
                };
            }
        }
        return 'string';
    }

    private function summarize(array $rules): ?string
    {
        $rules = array_values(array_filter(array_map(static fn ($r) => is_string($r) ? trim($r) : $r, $rules), static fn ($r) => $r !== ''));
        if ($rules === []) {
            return null;
        }
        return implode(' · ', $rules);
    }
}