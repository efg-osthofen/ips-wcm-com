<?php

/**
 * Minimal in-memory fake of the IP-Symcon runtime.
 *
 * It only covers what WCMConnector uses: properties, variables, timers,
 * variable profiles, instance buffers, semaphores and the message log.
 */

const KL_ERROR = 10206;

final class IpsFake
{
    /** @var array<int, array<string, mixed>> [instanceId][name] => value */
    public static array $properties = [];

    /** @var array<int, array{instance:int, ident:string, name:string, type:int, profile:string, position:int, value:mixed, action:bool}> */
    public static array $variables = [];

    /** @var array<int, array<string, array{interval:int, script:string}>> */
    public static array $timers = [];

    /** @var array<string, array{type:int, min:float, max:float, step:float, associations:array<int|string, string>}> */
    public static array $profiles = [];

    /** @var array<string, true> */
    public static array $semaphores = [];

    /** @var list<int> milliseconds passed to every IPS_SemaphoreEnter call */
    public static array $semaphoreWaits = [];

    /** @var list<array{instance:int, message:string, type:int}> */
    public static array $log = [];

    /** @var list<array{instance:int, message:string, data:string}> */
    public static array $debug = [];

    /** @var array<int, array<string, string>> [instanceId][name] => data, see SetBuffer() */
    public static array $buffers = [];

    /** Number of IPS_SetDisabled calls */
    public static int $setDisabledCalls = 0;

    private static int $nextId = 20000;

    public static function reset(): void
    {
        self::$properties = [];
        self::$variables = [];
        self::$timers = [];
        self::$profiles = [];
        self::$semaphores = [];
        self::$semaphoreWaits = [];
        self::$log = [];
        self::$debug = [];
        self::$buffers = [];
        self::$setDisabledCalls = 0;
        self::$nextId = 20000;
    }

    /** Simulates changing the instance configuration in the console. */
    public static function configure(int $instanceId, array $properties): void
    {
        foreach ($properties as $name => $value) {
            self::$properties[$instanceId][$name] = $value;
        }
    }

    public static function variableId(int $instanceId, string $ident): ?int
    {
        foreach (self::$variables as $id => $variable) {
            if ($variable['instance'] === $instanceId && $variable['ident'] === $ident) {
                return $id;
            }
        }

        return null;
    }

    public static function variable(int $instanceId, string $ident): array
    {
        $id = self::variableId($instanceId, $ident);
        if ($id === null) {
            throw new RuntimeException("Variable with ident '$ident' does not exist");
        }

        return self::$variables[$id];
    }

    /** @return list<string> */
    public static function idents(int $instanceId): array
    {
        $idents = [];
        foreach (self::$variables as $variable) {
            if ($variable['instance'] === $instanceId) {
                $idents[] = $variable['ident'];
            }
        }

        return $idents;
    }

    public static function nextId(): int
    {
        return self::$nextId++;
    }
}

class IPSModule
{
    protected int $InstanceID;

    public function __construct(int $InstanceID)
    {
        $this->InstanceID = $InstanceID;
    }

    public function Create()
    {
    }

    public function ApplyChanges()
    {
    }

    protected function RegisterPropertyString(string $name, string $default)
    {
        $this->registerProperty($name, $default);
    }

    protected function RegisterPropertyInteger(string $name, int $default)
    {
        $this->registerProperty($name, $default);
    }

    protected function RegisterPropertyBoolean(string $name, bool $default)
    {
        $this->registerProperty($name, $default);
    }

    protected function RegisterPropertyFloat(string $name, float $default)
    {
        $this->registerProperty($name, $default);
    }

    protected function ReadPropertyString(string $name): string
    {
        return (string) $this->readProperty($name);
    }

    protected function ReadPropertyInteger(string $name): int
    {
        return (int) $this->readProperty($name);
    }

    protected function ReadPropertyBoolean(string $name): bool
    {
        return (bool) $this->readProperty($name);
    }

    protected function ReadPropertyFloat(string $name): float
    {
        return (float) $this->readProperty($name);
    }

    protected function RegisterTimer(string $ident, int $interval, string $script)
    {
        IpsFake::$timers[$this->InstanceID][$ident] = ['interval' => $interval, 'script' => $script];
    }

    protected function SetTimerInterval(string $ident, int $interval)
    {
        if (!isset(IpsFake::$timers[$this->InstanceID][$ident])) {
            throw new RuntimeException("Timer '$ident' is not registered");
        }
        IpsFake::$timers[$this->InstanceID][$ident]['interval'] = $interval;
    }

    protected function RegisterVariableBoolean(string $ident, string $name, string $profile = '', int $position = 0): int
    {
        return $this->registerVariable(0, $ident, $name, $profile, $position);
    }

    protected function RegisterVariableInteger(string $ident, string $name, string $profile = '', int $position = 0): int
    {
        return $this->registerVariable(1, $ident, $name, $profile, $position);
    }

    protected function RegisterVariableFloat(string $ident, string $name, string $profile = '', int $position = 0): int
    {
        return $this->registerVariable(2, $ident, $name, $profile, $position);
    }

    protected function RegisterVariableString(string $ident, string $name, string $profile = '', int $position = 0): int
    {
        return $this->registerVariable(3, $ident, $name, $profile, $position);
    }

    protected function EnableAction(string $ident)
    {
        IpsFake::$variables[$this->GetIDForIdent($ident)]['action'] = true;
    }

    protected function GetIDForIdent(string $ident): int
    {
        $id = IpsFake::variableId($this->InstanceID, $ident);
        if ($id === null) {
            throw new RuntimeException("Variable with ident '$ident' does not exist");
        }

        return $id;
    }

    protected function SetValue(string $ident, $value): bool
    {
        return SetValue($this->GetIDForIdent($ident), $value);
    }

    protected function GetValue(string $ident)
    {
        return GetValue($this->GetIDForIdent($ident));
    }

    protected function LogMessage(string $message, int $type)
    {
        IpsFake::$log[] = ['instance' => $this->InstanceID, 'message' => $message, 'type' => $type];
    }

    protected function SendDebug(string $message, string $data, int $format)
    {
        IpsFake::$debug[] = ['instance' => $this->InstanceID, 'message' => $message, 'data' => $data];
    }

    protected function SetBuffer(string $name, string $data)
    {
        IpsFake::$buffers[$this->InstanceID][$name] = $data;
    }

    protected function GetBuffer(string $name): string
    {
        return IpsFake::$buffers[$this->InstanceID][$name] ?? '';
    }

    private function registerProperty(string $name, $default): void
    {
        if (!array_key_exists($name, IpsFake::$properties[$this->InstanceID] ?? [])) {
            IpsFake::$properties[$this->InstanceID][$name] = $default;
        }
    }

    private function readProperty(string $name)
    {
        if (!array_key_exists($name, IpsFake::$properties[$this->InstanceID] ?? [])) {
            throw new RuntimeException("Property '$name' is not registered");
        }

        return IpsFake::$properties[$this->InstanceID][$name];
    }

    private function registerVariable(int $type, string $ident, string $name, string $profile, int $position): int
    {
        $id = IpsFake::variableId($this->InstanceID, $ident);
        if ($id !== null) {
            IpsFake::$variables[$id]['name'] = $name;
            IpsFake::$variables[$id]['profile'] = $profile;
            IpsFake::$variables[$id]['position'] = $position;

            return $id;
        }

        $id = IpsFake::nextId();
        IpsFake::$variables[$id] = [
            'instance' => $this->InstanceID,
            'ident' => $ident,
            'name' => $name,
            'type' => $type,
            'profile' => $profile,
            'position' => $position,
            'value' => [false, 0, 0.0, ''][$type],
            'action' => false,
            'disabled' => false,
        ];

        return $id;
    }
}

function SetValue(int $variableId, $value): bool
{
    if (!isset(IpsFake::$variables[$variableId])) {
        throw new RuntimeException("Variable #$variableId does not exist");
    }

    IpsFake::$variables[$variableId]['value'] = match (IpsFake::$variables[$variableId]['type']) {
        0 => (bool) $value,
        1 => (int) $value,
        2 => (float) $value,
        3 => (string) $value,
    };

    return true;
}

function GetValue(int $variableId)
{
    if (!isset(IpsFake::$variables[$variableId])) {
        throw new RuntimeException("Variable #$variableId does not exist");
    }

    return IpsFake::$variables[$variableId]['value'];
}

/** Non-blocking: a held semaphore fails immediately instead of waiting. */
function IPS_SemaphoreEnter(string $name, int $milliseconds): bool
{
    IpsFake::$semaphoreWaits[] = $milliseconds;
    if (isset(IpsFake::$semaphores[$name])) {
        return false;
    }
    IpsFake::$semaphores[$name] = true;

    return true;
}

function IPS_SemaphoreLeave(string $name): bool
{
    if (!isset(IpsFake::$semaphores[$name])) {
        return false;
    }
    unset(IpsFake::$semaphores[$name]);

    return true;
}

/** Only the fields WCMConnector uses. */
function IPS_GetObject(int $objectId): array
{
    if (!isset(IpsFake::$variables[$objectId])) {
        throw new RuntimeException("Object #$objectId does not exist");
    }

    return [
        'ObjectID' => $objectId,
        'ObjectIdent' => IpsFake::$variables[$objectId]['ident'],
        'ObjectName' => IpsFake::$variables[$objectId]['name'],
        'ObjectIsDisabled' => IpsFake::$variables[$objectId]['disabled'],
    ];
}

function IPS_SetDisabled(int $objectId, bool $disabled): bool
{
    if (!isset(IpsFake::$variables[$objectId])) {
        throw new RuntimeException("Object #$objectId does not exist");
    }
    IpsFake::$variables[$objectId]['disabled'] = $disabled;
    IpsFake::$setDisabledCalls++;

    return true;
}

function IPS_VariableProfileExists(string $name): bool
{
    return isset(IpsFake::$profiles[$name]);
}

function IPS_CreateVariableProfile(string $name, int $type): bool
{
    if (isset(IpsFake::$profiles[$name])) {
        throw new RuntimeException("Profile '$name' already exists");
    }
    IpsFake::$profiles[$name] = ['type' => $type, 'min' => 0.0, 'max' => 0.0, 'step' => 0.0, 'associations' => []];

    return true;
}

function IPS_SetVariableProfileValues(string $name, float $min, float $max, float $step): bool
{
    IpsFake::$profiles[$name]['min'] = $min;
    IpsFake::$profiles[$name]['max'] = $max;
    IpsFake::$profiles[$name]['step'] = $step;

    return true;
}

function IPS_SetVariableProfileAssociation(string $name, float $value, string $text, string $icon, int $color): bool
{
    IpsFake::$profiles[$name]['associations'][(int) $value] = $text;

    return true;
}
