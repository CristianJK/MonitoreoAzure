<?php

namespace App\Services;

use App\Models\User;

/**
 * Un usuario solo puede modificar los work items asignados a él
 * (System.AssignedTo). Los demás son de solo lectura.
 */
class WorkItemOwnership
{
    public const DENIED_MESSAGE = 'Solo puedes modificar work items asignados a ti.';

    public function __construct(protected AzureDevOpsService $azure) {}

    /**
     * Consulta el responsable actual en Azure (sin cache, para que una
     * reasignación quite el permiso de inmediato).
     *
     * @return bool|array{error: string, status: int}
     */
    public function check(User $user, int $workItemId): bool|array
    {
        $result = $this->azure->getWorkItemAssignee($workItemId);

        if (isset($result['error'])) {
            return $result;
        }

        return self::matches($user, $result['assignee']);
    }

    /**
     * Compara contra el id de identidad y el uniqueName (ambos únicos en la
     * organización). Un work item sin asignar no es de nadie.
     */
    public static function matches(User $user, ?array $identity): bool
    {
        if (! $identity) {
            return false;
        }

        $id = strtolower((string) ($identity['id'] ?? ''));
        $uniqueName = strtolower((string) ($identity['uniqueName'] ?? ''));

        return ($id !== '' && $id === strtolower((string) $user->azure_id))
            || ($uniqueName !== '' && $uniqueName === strtolower($user->email));
    }
}
