<?php declare(strict_types=1);

namespace DhGithubUpdater\Service;

use DhGithubUpdater\Github\GitRef;
use DhGithubUpdater\Package\PluginManifest;

final class VersionCandidate
{
    public function __construct(
        public readonly GitRef $ref,
        public readonly PluginManifest $manifest,
    ) {
    }

    /**
     * @return array{ref: string, type: string, commit: string, version: ?string, pluginName: string, label: string}
     */
    public function toArray(): array
    {
        $label = $this->ref->name;
        if ($this->manifest->version !== null && ltrim($this->manifest->version, 'vV') !== ltrim($this->ref->name, 'vV')) {
            $label .= ' (v' . ltrim($this->manifest->version, 'vV') . ')';
        }
        if ($this->ref->type === GitRef::TYPE_BRANCH) {
            $label .= ' [Branch @ ' . substr($this->ref->commitSha, 0, 7) . ']';
        }

        return [
            'ref' => $this->ref->name,
            'type' => $this->ref->type,
            'commit' => $this->ref->commitSha,
            'version' => $this->manifest->version,
            'pluginName' => $this->manifest->pluginName,
            'label' => $label,
        ];
    }
}
