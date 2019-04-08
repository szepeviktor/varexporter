<?php

declare(strict_types=1);

namespace Brick\VarExporter\Internal;

use Brick\VarExporter\ExportException;
use Brick\VarExporter\VarExporter;

/**
 * The main exporter implementation, that handles variables of any type.
 *
 * A GenericExporter is only intended to be used once per array/object graph (i.e. once per `VarExport::export()` call),
 * as it keeps an internal cache of visited objects; if it is ever going to be reused, just implement a reset method to
 * reset the visited objects.
 *
 * @internal This class is for internal use, and not part of the public API. It may change at any time without warning.
 */
final class GenericExporter
{
    /**
     * @var ObjectExporter[]
     */
    private $objectExporters = [];

    /**
     * The children of every object found, to detect circular references.
     *
     * This is a two-level map of parent object hash => child object hash => path where the object first appeared.
     * [string => [string => string[]]]
     *
     * @var array
     */
    private $objectChildren = [];

    /**
     * @var bool
     */
    public $addTypeHints;

    /**
     * @var bool
     */
    public $skipDynamicProperties;

    /**
     * @param int $options
     */
    public function __construct(int $options)
    {
        $this->objectExporters[] = new ObjectExporter\StdClassExporter($this);
        $this->objectExporters[] = new ObjectExporter\InternalClassExporter($this);

        if (! ($options & VarExporter::NO_SET_STATE)) {
            $this->objectExporters[] = new ObjectExporter\SetStateExporter($this);
        }

        if (! ($options & VarExporter::NO_SERIALIZE)) {
            $this->objectExporters[] = new ObjectExporter\SerializeExporter($this);
        }

        if (! ($options & VarExporter::NOT_ANY_OBJECT)) {
            $this->objectExporters[] = new ObjectExporter\AnyObjectExporter($this);
        }

        $this->addTypeHints          = (bool) ($options & VarExporter::ADD_TYPE_HINTS);
        $this->skipDynamicProperties = (bool) ($options & VarExporter::SKIP_DYNAMIC_PROPERTIES);
    }

    /**
     * @param mixed    $var     The variable to export.
     * @param string[] $path    The path to the current variable in the array/object graph.
     * @param string[] $parents The hashes of all objects higher in the graph.
     *
     * @return string[] The lines of code.
     *
     * @throws ExportException
     */
    public function export($var, array $path, array $parents) : array
    {
        switch ($type = gettype($var)) {
            case 'boolean':
            case 'integer':
            case 'double':
            case 'string':
                return [var_export($var, true)];

            case 'NULL':
                // lowercase null
                return ['null'];

            case 'array':
                return $this->exportArray($var, $path, $parents);

            case 'object':
                return $this->exportObject($var, $path, $parents);

            default:
                // resources
                throw new ExportException(sprintf('Type "%s" is not supported.', $type), $path);
        }
    }

    /**
     * @param array    $array   The array to export.
     * @param string[] $path    The path to the current array in the array/object graph.
     * @param string[] $parents The hashes of all objects higher in the graph.
     *
     * @return string[] The lines of code.
     *
     * @throws ExportException
     */
    public function exportArray(array $array, array $path, array $parents) : array
    {
        if (! $array) {
            return ['[]'];
        }

        $result = [];

        $result[] = '[';

        $count = count($array);
        $isNumeric = array_keys($array) === range(0, $count - 1);

        $current = 0;

        foreach ($array as $key => $value) {
            $isLast = (++$current === $count);

            $newPath = $path;
            $newPath[] = (string) $key;

            $exported = $this->export($value, $newPath, $parents);

            $prepend = '';
            $append = '';

            if (! $isNumeric) {
                $prepend = var_export($key, true) . ' => ';
            }

            if (! $isLast) {
                $append = ',';
            }

            $exported = $this->wrap($exported, $prepend, $append);
            $exported = $this->indent($exported);

            $result = array_merge($result, $exported);
        }

        $result[] = ']';

        return $result;
    }

    /**
     * @param object   $object  The object to export.
     * @param string[] $path    The path to the current object in the array/object graph.
     * @param string[] $parents The hashes of all objects higher in the graph.
     *
     * @return string[] The lines of code.
     *
     * @throws ExportException
     */
    public function exportObject($object, array $path, array $parents) : array
    {
        $hash = spl_object_hash($object);

        foreach ($parents as $parentHash) {
            if (isset($this->objectChildren[$parentHash][$hash])) {
                throw new ExportException(sprintf(
                    'Object of class "%s" has a circular reference at %s. ' .
                    'Circular references are currently not supported.',
                    get_class($object),
                    ExportException::pathToString($this->objectChildren[$parentHash][$hash])
                ), $path);
            }

            $this->objectChildren[$parentHash][$hash] = $path;
        }

        $reflectionObject = new \ReflectionObject($object);

        foreach ($this->objectExporters as $objectExporter) {
            if ($objectExporter->supports($reflectionObject)) {
                return $objectExporter->export($object, $reflectionObject, $path, $parents);
            }
        }

        // This may only happen when an option is given to disallow specific export methods.

        $className = $reflectionObject->getName();

        throw new ExportException('Class "' . $className . '" cannot be exported using the current options.', $path);
    }

    /**
     * Indents every non-empty line.
     *
     * @param string[] $lines The lines of code.
     *
     * @return string[] The indented lines of code.
     */
    public function indent(array $lines) : array
    {
        foreach ($lines as & $value) {
            if ($value !== '') {
                $value = '    ' . $value;
            }
        }

        return $lines;
    }

    /**
     * @param string[] $lines   The lines of code.
     * @param string   $prepend The string to prepend to the first line.
     * @param string   $append  The string to append to the last line.
     *
     * @return string[]
     */
    public function wrap(array $lines, string $prepend, string $append) : array
    {
        $lines[0] = $prepend . $lines[0];
        $lines[count($lines) - 1] .= $append;

        return $lines;
    }
}
