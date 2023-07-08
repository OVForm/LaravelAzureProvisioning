<?php

namespace RobTrehy\LaravelAzureProvisioning;

use RobTrehy\LaravelAzureProvisioning\Exceptions\AzureProvisioningException;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use RobTrehy\LaravelAzureProvisioning\Utils\AzureHelper;

class AzureProvisioningProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/Config/azureprovisioning.php',
            'azureprovisioning'
        );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(Router $router)
    {
        $this->publishes(
            [__DIR__.'/Config/azureprovisioning.php' => config_path('azureprovisioning.php')],
            'azureprovisioning'
        );

        $router->bind(
            'resourceType',
            function ($name) {
                $config = config('azureprovisioning.'.$name);

                if ($config === null) {
                    throw (new AzureProvisioningException(sprintf('No resource %s found.', $name)))->setCode(404);
                }

                $resourceType = "RobTrehy\LaravelAzureProvisioning\Resources\\".$name."ResourceType";

                return new $resourceType($name, $config);
            }
        );

        $router->bind(
            'resourceObject',
            function ($id, $route) {
                $resourceType = $route->parameter('resourceType');

                if (!$resourceType) {
                    throw (new AzureProvisioningException('ResourceType not provided'))->setCode(404);
                }

                $primaryKeyName = config("azureprovisioning.{$resourceType->getName()}.primaryKey") ?? "id";
                $model = $resourceType->getModel();
                $resourceObject = $model::where($primaryKeyName, (string)$id)->first();

                if ($resourceObject === null) {
                    throw (new AzureProvisioningException(sprintf('Resource %s not found', $id)))->setCode(404);
                }

                if (($matchIf = \request()->header('IF-match'))) {
                    $versionsAllowed = preg_split('/\s*,\s*/', $matchIf);
                    $currentVersion = AzureHelper::getResourceObjectVersion($resourceObject);

                    if (!in_array($currentVersion, $versionsAllowed) && !in_array('*', $versionsAllowed)) {
                        throw (new AzureProvisioningException('Failed to update. Resource changed on the server.'))
                            ->setCode(412);
                    }
                }

                return $resourceObject;
            }
        );

        $this->loadRoutesFrom(__DIR__.'/Routes/scim.php');
    }
}
