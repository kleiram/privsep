<?php
/**
 * Copyright (c) 2017 Martijn van Duren (Rootnet) <m.vanduren@rootnet.nl>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

declare(strict_types=1);

namespace Rootnet\Privsep;


use Rootnet\Privsep\Error\RemoteError;
use Rootnet\Privsep\Remote\Fallback;
use Rootnet\Privsep\Whitelist\WhitelistInterface;

final class Client
{
    public $trace = false;
    public $traceStrlen = 18;
    private $closures = [];
    private $handshakeCount = 0;
    private $ignoreUnknownInput = false;
    private $lid;
    private $newObjects = [];
    private $objects = [];
    private $passThrowReenter = 0;
    private $returns = [];
    private $readbuf = "";
    private $rclosures = [];
    private $remoteError;
    private $rid;
    private $robjects = [];
    private $sock;
    private $throwableToNetReenter = 0;
    private $whitelist;

    public static $warnWeakref = true;
    private static $instances = [];

    public function __construct(
        $sock,
        WhitelistInterface $whitelist = null,
        bool $usePid = false
    ) {
        if (@get_resource_type($sock) !== "stream") {
            $bt = debug_backtrace();
            throw new \TypeError("Argument 1 passed to " . __CLASS__ .
                "::__construct() must be of the type resource (stream), " .
                gettype($sock) . " given, called in " . $bt[0]["file"] .
                " on line " .  $bt[0]["line"]);
        }

        if (isset($whitelist)) {
            $this->whitelist = $whitelist;
        }

        $this->sock = $sock;
        $this->handshake($usePid);

        self::$instances[(int)$sock] = $this;
    }

/*
 * This method, although not dangerous, is not intended for public use.
 * Use \Rootnet\Privsep\Remote interfaces if at all possible.
 */
    public function call($function, array &$arguments, $class = null)
    {
        if (isset($this->remoteError)) {
            throw $this->remoteError;
        }
        if (!is_string($function) && !$function instanceof \Closure) {
            $bt = debug_backtrace();
            throw new \TypeError("Argument 1 passed to " . __CLASS__ .
                "::call() must be of the type string or callable, " .
                gettype($function) . " given, called in " . $bt[0]["file"] .
                " on line " . $bt[0]["line"]);
        }

        if (
            isset($class) &&
            !is_a($class, "\\Rootnet\\Privsep\\Remote", true)
        ) {
            $bt = debug_backtrace();
            throw new \TypeError("Argument 3 passed to " . __CLASS__ .
                "::call() must be of the type string or " .
                "\\Rootnet\\Privsep\\Remote, " .  gettype($class) .
                " given, called in " . $bt[0]["file"] . " on line " .
                $bt[0]["line"]);
        }

        $call["type"] = "call";
        $call["id"] = random_int(0, 0xFFFFFFFF);
        $call["function"] = $function;
        $call["arguments"] = $arguments;
        if (is_a($function, "\\Closure")) {
            assert(!isset($class));
        }
        if (isset($class)) {
            $call["class"] = $class;
/*
 * Workaround for locally constructed objects
 * This is used in self::readArray() to do the linking
 */
            if (
                is_object($class) && (
                    $function === "__construct" ||
                    $function === "__clone"
                )
            ) {
                $id = array_search($class, $this->objects, true);
                if ($id === false) {
                    $id = $this->weakArraySearch($class, $this->robjects, true);
                    if ($id === false) {
/*
 * This should only be reached via
 * \Rootnet\Privsep\Remote::__construct() or
 * \Rootnet\Privsep\Remote::__clone(), so there's
 * no need to array_search $this->newObjects.
 */
                        $this->newObjects[$call["id"]] = $class;
                        $call["class"] = get_class($class);
                    } elseif ($function === "__clone") {
                        $arguments = $call["arguments"] = [];
                    }
                }
            }
        }

        $this->writeArray($call);
        $res = $this->waitRes($call["id"]);
        assert(
            empty(array_diff_key($res["arguments"], $arguments)) &&
            empty(array_diff_key($arguments, $res["arguments"]))
        );
        foreach ($arguments as $key => $value) {
            $arguments[$key] = $res["arguments"][$key];
        }

        if (is_object($class) && $function === "__destruct") {
            $id = $this->weakArraySearch($class, $this->robjects, true);
            if ($id !== false) {
                unset($this->robjects[$id]);
            }
        }

        return $res["return"];
    }

    public static function waitInput(self $winstance = null): bool
    {
        $null = null;
        $read = [];
        $timeout = null;
        $instance = null;
        try {
            foreach (self::$instances as $instance) {
                if (isset($instance->remoteError)) {
                    if ($instance === $winstance) {
                        throw $instance->remoteError;
                    }
                    continue;
                }
                $read[] = $instance->sock;
                while (@unserialize($instance->readbuf) !== false) {
                    $instance->handleInput();
                    $timeout = 0;
                }
            }
            if (empty($read)) {
                return false;
            }

// rely on timeouts from privsepd or sapi
            stream_select($read, $null, $null, $timeout);
            foreach ($read as $sock) {
                self::$instances[(int)$sock]->handleInput();
            }
        } catch (RemoteError $e) {
            if (
                $e->getCode() === 1 ||
                $instance === $winstance
            ) {
                throw $e;
            }
            return self::waitInput();
        }
        return true;
    }

    private function closureToNet(\Closure $closure): \stdClass
    {
        $nclosure = new \stdClass;
        $nclosure->type = "closure";
        $id = array_search($closure, $this->rclosures, true);
        if ($id !== false) {
            $nclosure->owner = $this->rid;
        } else {
            $nclosure->owner = $this->lid;
            $id = array_search($closure, $this->closures, true);
            if ($id === false) {
                $this->closures[] = $closure;
                end($this->closures);
                $id = key($this->closures);
            }
        }
        $nclosure->id = $id;
        return $nclosure;
    }

    private function traceCall(
        int $id,
        $function,
        array $arguments,
        $class = null
    ): void {
        if (!$this->trace) {
            return;
        }

        trigger_error(
            $this->traceString($id, $function, $arguments, $class),
            E_USER_NOTICE
        );
    }

    private function traceClass($class): string
    {
        if (is_string($class)) {
            return $class;
        }

        $cname = "";
        assert(is_object($class));
        if ($class instanceof \Closure) {
            $id = array_search($class, $this->closures, true);
            if ($id !== false) {
                $cname = "Closure";
            } else {
                $id = array_search($class, $this->rclosures, true);
                if ($id !== false) {
                    $cname = "rClosure";
                }
            }
        } else {
            $id = array_search($class, $this->objects, true);
            if ($id !== false) {
                $cname = "Object(" . get_class($class) . ")";
            } else {
                $id = $this->weakArraySearch($class, $this->robjects, true);
                if ($id != false) {
                    $cname = "rObject(" . get_class($class) . ")";
                }
            }
        }
        assert(!empty($cname));
        assert($id !== false);

        $cname .= "#" . $id;
        return $cname;
    }

    private function traceForbidden(
        int $id,
        $function,
        array $arguments,
        $class = null
    ): void {
        trigger_error(
            "Forbidden: " . $this->traceString(
                $id,
                $function,
                $arguments,
                $class
            ),
            E_USER_NOTICE
        );
    }

    private function traceThrow(int $id): void
    {
        if (!$this->trace) {
            return;
        }

        trigger_error(sprintf("THROWN #%X", $id), E_USER_NOTICE);
    }

    private function traceReturn(int $id, $return): void
    {
        if (!$this->trace) {
            return;
        }
        $this->ensureLineObjectIds($return);
        $traceReturn = sprintf("RET #%X %s", $id, $this->traceVar($return));

        trigger_error($traceReturn, E_USER_NOTICE);
    }

    private function traceString(
        int $id,
        $function,
        array $arguments,
        $class = null
    ): string {
        $traceCall = sprintf("#%X ", $id);
        if (isset($class)) {
            $traceCall .= $this->traceClass($class);
            $traceCall .= is_object($class) ? "->" : "::";
        }
        if ($function instanceof \Closure) {
            $traceCall .= $this->traceClass($function) . "(";
        } else {
            $traceCall .= $function . "(";
        }
        $firstArgument = true;
        foreach ($arguments as $argument) {
            if ($firstArgument) {
                $firstArgument = false;
            } else {
                $traceCall .= ", ";
            }
            $traceCall .= $this->traceVar($argument);
        }
        $traceCall .= ")";
        return $traceCall;
    }

    private function traceVar($var)
    {
        if (is_string($var)) {
            if (!is_int($this->traceStrlen)) {
                throw new \Error("traceStrlen is not an integer");
            }
// Always show at least the first character
            $strlen = $this->traceStrlen < 4 ? 4 : $this->traceStrlen;
            if (strlen($var) > $strlen) {
                return "\"" . substr($var, 0, $strlen - 3) . "...\"";
            } else {
                return "\"" . $var . "\"";
            }
        } elseif (is_object($var)) {
            return $this->traceClass($var);
        } elseif (is_array($var)) {
            return "array";
        } elseif (is_null($var)) {
            return "null";
        } else {
            return $var;
        }
    }

// toLine gives us these ids for free.
    private function ensureLineObjectIds($var)
    {
        $this->toLine($var);
    }

    private function fromLine($var)
    {
        switch (gettype($var)) {
            case "array":
                foreach ($var as $key => $value) {
                    $var[$key] = $this->fromLine($value);
                }
                break;
            case "object":
                assert(
                    isset($var->type) &&
                    (
                        $var->type === "closure" ||
                        $var->type === "object" ||
                        $var->type === "throwable"
                    )
                );
                switch ($var->type) {
                    case "closure":
                        return $this->netToClosure($var);
                    case "object":
                        return $this->netToObject($var);
                    case "throwable":
                        return $this->netToThrowable($var);
                }
                break;
            default:
                break;
        }
        return $var;
    }

    private function handleInput(): void
    {
        $input = $this->readArray();

        switch ($input["type"]) {
            case "throwable":
                $input["throw"]->remoteClientReceived = true;
                throw $input["throw"];
            case "return":
                assert(is_int($input["id"]));
                assert(array_key_exists("return", $input));
                assert(is_array($input["arguments"]));
                $this->returns[$input["id"]] = $input;
                break;
            case "call":
                assert(isset($input["id"]) && is_int($input["id"]));
                assert(
                    isset($input["function"]) &&
                    (
                        is_string($input["function"]) ||
                        $input["function"] instanceof \Closure
                    )
                );
                assert(
                    isset($input["arguments"]) &&
                    is_array($input["arguments"])
                );
                $class = null;
                if (isset($input["class"])) {
                    assert(
                        is_string($input["class"]) ||
                        is_object($input["class"])
                    );
                    $class = $input["class"];
                }
                $function = $input["function"];
                $arguments = $input["arguments"];
                $id = $input["id"];
                $grants = $this->verifyCall($function, $arguments, $class);
                assert(is_bool($grants["allow"]));
                if (!$grants["allow"]) {
                    $this->traceForbidden($id, $function, $arguments, $class);
                    assert(
                        isset($grants["throw"]) xor
                        array_key_exists("return", $grants)
                    );
                    if (isset($grants["throw"])) {
                        assert($grants["throw"] instanceof \Error);
                    }
                    if (isset($grants["throw"])) {
                        $this->throw($grants["throw"]);
                    } else {
                        $call["type"] = "return";
                        $call["id"] = $id;
                        $call["return"] = $grants["return"];
                        $call["arguments"] = $arguments;
                        $this->writeArray($call);
                    }
                    return;
                }
                $this->traceCall($id, $function, $arguments, $class);
                try {
                    if (!isset($class)) {
                        $return = $function(...$arguments);
                    } else {
                        switch ($function) {
                            case "__construct":
                                if (is_object($class)) {
                                    $return =
                                        $class->__construct(...$arguments);
                                } else {
                                    $return = new $class(...$arguments);
                                }
                                break;
                            case "__destruct":
                                assert(is_object($class));
                                $class = $this->objectToNet($class);
                                assert($class->owner == $this->lid);
                                unset($this->objects[$class->id]);
                                $return = null;
                                break;
                            case "__clone":
                                if (
                                    is_string($class) &&
                                    $arguments[0] instanceof $class
                                ) {
                                    $return = clone $arguments[0];
                                } else {
                                    assert(is_object($class));
                                    $return = $class->__clone(...$arguments);
                                }
                                break;
                            case "__get":
                                $return = $class->{$arguments[0]};
                                break;
                            case "__set":
                                $class->{$arguments[0]} = $arguments[1];
                                $return = null;
                                break;
                            case "__isset":
                                $return = isset($class->{$arguments[0]});
                                break;
                            case "__unset":
                                unset($class->{$arguments[0]});
                                $return = null;
                                break;
                            case "__tostring":
                                $return = (string)$class;
                                break;
                            case "__debugInfo":
                                $return = [];
                                if (!isset($this->whitelist)) {
                                    break;
                                }
                                foreach (
                                    $this->whitelist->publicAttributes($class)
                                    as $attribute
                                ) {
                                    try {
                                        $return[$attribute] =
                                            $class->$attribute;
                                    } catch (\Throwable $t) {
                                        /* Ignore */
                                    }
                                }
                                break;
                            default:
                                if (is_object($class)) {
                                    $return = $class->$function(...$arguments);
                                } else {
                                    $return = $class::$function(...$arguments);
                                }
                        }
                    }
                    $this->traceReturn($id, $return);
                    $call["type"] = "return";
                    $call["id"] = $id;
                    $call["return"] = $return;
                    $call["arguments"] = $arguments;
                    $this->writeArray($call);
                    break;
                } catch (\Throwable $t) {
                    $this->traceThrow($id);
                    assert(is_array($grants["catch"]));
                    $t->grants = $grants["catch"];
                    $this->throw($t);
                }
                break;
            default:
                if (!$this->ignoreUnknownInput) {
/* Gently explode in your face */
                    throw new \Error("Unknown input: " . $input["type"]);
                }
        }
    }

    private function handshake(bool $usePid): void
    {
/*
 * The handshake includes the supported network facing protocol version numbers.
 * The major component indicates any backwards incompatible changes, the minor
 * component indicate additions to the protocol that are backwards compatible.
 *
 * If multiple versions are supported they should be send over via a comma
 * delimited list. The highest common version should be chosen without further
 * verification.
 *
 * With the introduction of new features the changed behavior should not break
 * existing functionality and the added commands should be silently ignored.
 *
 * A good portion of this code should probably be rewritten as soon as soon as
 * a new minor version is introduced.
 *
 * Introduction of changes should be kept to a minimum.
 * This is privsepd, not kitchensinkd!
 */
        $welcome = "ROPE [1.0]: ";
/*
 * Assume that if we need 5 handshakes both sides use pid for identification and
 * they happen to be the same. 2 should be enough in most conditions, but pick a
 * few more to be on the safe side.
 */
        if ($this->handshakeCount++ >= 5) {
            throw new \Error("Too many handshake retries.");
        }
/*
 * Limit to 99999 MAX for readability. It's not intended to be cryptographically
 * secure, but merely usable for log identification.
 */
        $this->lid = $usePid ? posix_getpid() : random_int(0, 99999);
        $written = @fwrite($this->sock, $welcome . $this->lid . "\n");
        if ($written === false || $written === 0) {
            throw new \Error("Connection reset by peer");
        }
        $read = [$this->sock];
        $null = null;
        if (stream_select($read, $null, $null, 1) !== 1) {
            throw new \Error("Handshake timeout");
        }
        $response = fread($this->sock, 1024);
        if (empty($response)) {
            throw new \Error("Connection reset by peer");
        }

        if (($nl = strpos($response, "\n")) !== false) {
            $this->readbuf = substr($response, $nl + 1);
            $response = substr($response, 0, $nl);
        } else {
            throw new \Error("Received ROPE header too long");
        }

        if (strncmp($response, "ROPE [", strlen("ROPE [")) !== 0) {
            throw new \Error("Remote is not a valid ROPE client");
        }
        $response = substr($response, strlen("ROPE ["));
        if (preg_match("/^((\d+\.\d+),)*\d+\.\d+/", $response, $match) !== 1) {
            throw new \Error("Remote is not a valid ROPE client");
        }

        $response = substr($response, strlen($match[0]));
        $versions = explode(",", $match[0]);
        $compatible = false;
/* Accept any protocol version of 1.n */
        foreach ($versions as $version) {
            $version = explode(".", $version);
            if ((int) $version[0] === 1) {
                $compatible = true;
                if ((int) $version[1] > 0) {
                    $this->ignoreUnknownInput = true;
                    break;
                }
            }
        }
        if (!$compatible) {
            throw new \Error("No compatible protocol version found");
        }

        if (
            strncmp($response, "]: ", strlen("]: ")) !== 0 ||
            ($this->rid = substr($response, strlen("]: "))) === false ||
            !is_numeric($this->rid)
        ) {
            $remote = stream_socket_get_name($this->sock, true);
            if (empty($remote) || $remote[0] === "\0") {
                $remote = stream_socket_get_name($this->sock, false);
            };
            if (empty($remote) || $remote[0] === "\0") {
                throw new \Error("Remote is not a valid ROPE client");
            } else {
                throw new \Error(
                    "Remote '" . $remote . "' is not a valid ROPE client"
                );
            }
        }
        if (($this->rid = (int)$this->rid) === $this->lid) {
            $this->handshake($usePid);
        }
    }

    private function objectToNet($object): \stdClass
    {
        assert(is_object($object));
        $nobject = new \stdClass;
        $nobject->type = "object";
        $nobject->name = get_class($object);
        $id = $this->weakArraySearch($object, $this->robjects, true);
        if ($id !== false) {
            $nobject->owner = $this->rid;
        } else {
            $nobject->owner = $this->lid;
            $id = array_search($object, $this->objects, true);
            if ($id === false) {
                $this->objects[] = $object;
                end($this->objects);
                $id = key($this->objects);
            }
        }
        $nobject->id = $id;
        return $nobject;
    }

    private function netToClosure(\stdClass $nclosure): \Closure
    {
        assert($nclosure->type === "closure");
        assert(isset($nclosure->id, $nclosure->owner));
        if ($nclosure->owner === $this->lid) {
            assert(isset($this->closures[$nclosure->id]));
            return $this->closures[$nclosure->id];
        }
        if (!isset($this->rclosures[$nclosure->id])) {
            $closure = function (&...$arguments) use ($nclosure) {
                return $this->call($this->rclosures[$nclosure->id], $arguments);
            };
            $this->rclosures[$nclosure->id] = $closure;
        }
        return $this->rclosures[$nclosure->id];
    }

    private function netToObject(\stdClass $nobject)
    {
        assert($nobject->type === "object");
        assert(isset($nobject->id, $nobject->owner));
        $robject = null;
        if ($nobject->owner === $this->lid) {
            assert(isset($this->objects[$nobject->id]));
            return $this->objects[$nobject->id];
        }
        assert(isset($nobject->name));
        if (!isset($this->robjects[$nobject->id])) {
            if (is_a($nobject->name, "\\Rootnet\\Privsep\\Remote", true)) {
                $object = new $nobject->name($this);
            } else {
                $object = new Fallback($this);
            }
            $this->robjects[$nobject->id] = $object;
        }
        if (self::$warnWeakref && !extension_loaded("Weakref")) {
            trigger_error(
                "Please enable Weakref extension to prevent memory leaks",
                E_USER_WARNING
            );
            self::$warnWeakref = false;
        }
        if (
            extension_loaded("Weakref") &&
            !is_a($this->robjects[$nobject->id], "\\Weakref", false)
        ) {
// Hold reference for Weakref
            $robject = $this->robjects[$nobject->id];
            $this->robjects[$nobject->id] = new \Weakref($robject);
        }
        if (is_a($this->robjects[$nobject->id], "\\Weakref")) {
            return $this->robjects[$nobject->id]->get();
        }
        return $this->robjects[$nobject->id];
    }

    private function netToThrowable(\stdClass $nthrowable): \Throwable
    {
        assert($nthrowable->type === "throwable");
        assert(
            is_string($nthrowable->class) &&
            is_string($nthrowable->message) &&
            is_int($nthrowable->code) &&
            is_string($nthrowable->throwtype)
        );
        if (
            !class_exists($nthrowable->class) ||
            !is_a($nthrowable->class, "\\Throwable", true)
        ) {
            $nthrowable->class = $nthrowable->throwtype;
        }
        if ($nthrowable->class[0] !== "\\") {
            $nthrowable->class = "\\" . $nthrowable->class;
        }
        $previous = isset($nthrowable->previous) ?
            $this->netToThrowable($nthrowable->previous) :
            null;
        if (is_a($nthrowable->class, "\\ErrorException", true)) {
            $throwable = new $nthrowable->class(
                $nthrowable->message,
                $nthrowable->code,
                $nthrowable->severity,
                __FILE__,
                __LINE__,
                $previous
            );
        } else {
            $throwable = new $nthrowable->class(
                $nthrowable->message,
                $nthrowable->code,
                $previous
            );
        }
        return $throwable;
    }

    private function passThrow(\Throwable $t)
    {
        $this->passThrowReenter++;

        if (!isset($t->grants)) {
            return $t;
        }

        $grants = $t->grants;
        unset($t->grants);

        if (($previous = $t->getPrevious()) !== null) {
            $previous->grants = $grants;
            $previous = $this->passThrow($previous);
        }

        $throwable = "\\" . get_class($t);
        foreach ($grants as $grant) {
            assert(is_string($grant));
            if (substr($grant, -1) === "*") {
                if (!is_a($t, substr($grant, 0, -1))) {
                    continue;
                }
            } else {
                if (get_class($t) !== $grant) {
                    continue;
                }
            }
            $this->passThrowReenter--;
            if (is_a($throwable, "\\ErrorException", true)) {
                return new $throwable(
                    $t->getMessage(),
                    $t->getCode(),
                    $t->getSeverity(),
                    $t->getFile(),
                    $t->getLine(),
                    $previous
                );
            } else {
                return new $throwable(
                    $t->getMessage(),
                    $t->getCode(),
                    $previous
                );
            }
        }

        if (--$this->passThrowReenter === 0) {
            if (isset($previous)) {
                return $previous;
            }
            return new \Exception("Untransferable");
        } else {
            return $previous;
        }
    }

    private function printThrowable(\Throwable $t): ?string
    {
        if (
            isset($t->remoteClientReceived) &&
            $t->remoteClientReceived === true
        ) {
            return null;
        }
        if (($previous = $t->getPrevious()) !== null) {
            $str = $this->printThrowable($previous) . "\n\nNext ";
        } else {
            $str = "Caught ";
        }
        return $str . get_class($t) . ": " . $t->getMessage() . " in " .
            $t->getFile() . ":" . $t->getLine() . "\nStack trace:\n" .
            $t->getTraceAsString();
    }

    private function readArray(): array
    {
        while (($res = @unserialize($this->readbuf)) === false) {
            $read = stream_get_meta_data($this->sock)["unread_bytes"];
            if (
                ($res = fread($this->sock, !$read ? 1024 : $read)) === false ||
                $res === ""
            ) {
                $this->remoteError = new RemoteError(
                    "Connection reset by peer",
                    0
                );
                $this->remoteError->setActiveObjects($this->robjects);
                throw $this->remoteError;
            }
            $this->readbuf .= $res;
        }
        $this->readbuf = substr($this->readbuf, strlen(serialize($res)));

        assert(isset($res["type"]));
// Workaround for locally constructed objects
        if ($res["type"] === "return" && !empty($this->newObjects)) {
            assert(isset($res["id"]));
            if (isset($this->newObjects[$res["id"]])) {
                assert(
                    isset($res["return"]) &&
                    $res["return"] instanceof \stdClass
                );
                assert(isset($res["return"]->id));
                $this->robjects[$res["return"]->id] =
                    $this->newObjects[$res["id"]];
                unset($this->newObjects[$res["id"]]);
            }
        }
        $res = $this->fromLine($res);
        return $res;
    }

    private function throw(\Throwable $t): void
    {
        $call["type"] = "throwable";
        $call["throw"] = $t;
        $this->writeArray($call);
    }

    private function throwableToNet(\Throwable $t): \stdClass
    {
        $T = $this->throwableToNetReenter++ === 0 ? $this->passThrow($t) : $t;

        $nthrowable = new \stdClass;
        $nthrowable->type = "throwable";
        $nthrowable->class = get_class($T);
        $nthrowable->message = $T->getMessage();
        $nthrowable->code = $T->getCode();
        $nthrowable->throwtype =
            $T instanceof \ErrorException ? "ErrorException" :
            $T instanceof \Exception ? "Exception" :
            $T instanceof \DivisionByZeroError ? "DivisionByZeroError" :
            $T instanceof \ArithmeticError ? "ArithmeticError" :
            $T instanceof \AssertionError ? "AssertionError" :
            $T instanceof \ParseError ? "ParseError" :
            $T instanceof \TypeError ? "TypeError" :
            "Error";
        if (($p = $T->getPrevious()) !== null) {
            $nthrowable->previous = $this->throwableToNet($p);
        }
        if ($T instanceof \ErrorException) {
            $nthrowable->severity = $T->getSeverity();
        }
/*
 * Always log throwables. They contain sensitive information (backtraces) that
 * will be lost upon transfer and aren't intended to be passed around as
 * arguments.
 */
        if (--$this->throwableToNetReenter === 0) {
            if ($trace = $this->printThrowable($t)) {
                trigger_error($this->printThrowable($t), E_USER_WARNING);
            }
        }
        return $nthrowable;
    }

    private function toLine($var)
    {
        switch (gettype($var)) {
            case "array":
                foreach ($var as $key => $value) {
                    $var[$key] = $this->toLine($value);
                }
                break;
            case "object":
                if ($var instanceof \Closure) {
                    $var = $this->closureToNet($var);
                } elseif ($var instanceof \Throwable) {
                    $var = $this->throwableToNet($var);
                } else {
                    $var = $this->objectToNet($var);
                }
                break;
            case "resource":
                throw new \TypeError("Transferring resources not supported");
        }
        return $var;
    }

    private function verifyCall(
        $function,
        array $arguments,
        $class = null
    ): array {
        if ($function instanceof \Closure) {
            assert(!isset($class));
        }
        if (
            !isset($this->whitelist) && (
                $function instanceof \Closure ||
                $function === "__destruct" ||
                $function === "__debugInfo"
            )
        ) {
            return [
                "allow" => true,
                "catch" => []
            ];
        }
        return $this->whitelist->verifyCall($function, $arguments, $class);
    }

    private function waitRes(int $id): array
    {
        do {
            if (isset($this->returns[$id])) {
                $return = $this->returns[$id];
                unset($this->returns[$id]);
                return $return;
            }
            self::waitInput($this);
        } while (true);
    }

    private function weakArraySearch(
        $needle,
        array $haystack,
        bool $strict = false
    ) {
        if (!extension_loaded("Weakref")) {
            return array_search($needle, $haystack, $strict);
        }
        foreach ($haystack as $key => $straw) {
            if (is_a($straw, "\\Weakref")) {
                $straw = $straw->get();
            }
            if ($strict) {
                if ($needle === $straw) {
                    return $key;
                }
            } else {
                if ($needle == $straw) {
                    return $key;
                }
            }
        }
        return false;
    }

    private function writeArray(array $call): void
    {
        $call = $this->toLine($call);
        $call = serialize($call);

        $written = 0;
        do {
            substr($call, $written);
            $written = fwrite($this->sock, $call);
            if ($written === false || $written === 0) {
                $this->remoteError = new RemoteError(
                    "Connection reset by peer",
                    1
                );
                $this->remoteError->setActiveObjects($this->robjects);
                throw $this->remoteError;
            }
        } while ($written < strlen($call));
    }
}
