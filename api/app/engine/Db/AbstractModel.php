<?php
namespace Engine\Db;

use Phalcon\{
    DI,
    Mvc\Model as PhModel,
    Mvc\Model\Query\Builder as PhBuilder
};

/**
 * Abstract Model.
 *
 * @category  ThePhalconPHP
 * @author    Nguyen Duc Duy <nguyenducduy.it@gmail.com>
 * @copyright 2016-2017
 * @license   New BSD License
 * @link      http://thephalconphp.com/
 */
abstract class AbstractModel extends PhModel
{
    public function initialize()
    {
        self::getTableName();
    }

    /**
     * Get table name.
     *
     * @return string
     */
    public static function getTableName()
    {
        $reader = DI::getDefault()->get('annotations');
        $reflector = $reader->get(get_called_class());
        $annotations = $reflector->getClassAnnotations();

        return $annotations->get('Source')->getArgument(0);
    }

    /**
     * Paginator.
     * @param  [array] $params Condition query
     * @param  [integer] $limit  Limit page
     * @param  [integer] $offset Offset page
     * @return [object] Paginator object
     */
    public static function paginate($formData, $limit, $offset, $cache = false, $lifetime = 0)
    {
        $model = get_called_class();
        $whereString = '';
        $bindParams = [];
        $bindTypeParams = [];

        if (is_array($formData['conditions'])) {
            if (isset($formData['conditions']['keyword'])
                && strlen($formData['conditions']['keyword']) > 0
                && isset($formData['conditions']['searchKeywordIn'])
                && count($formData['conditions']['searchKeywordIn']) > 0) {
                /**
                 * Search keyword
                 */
                $searchKeyword = $formData['conditions']['keyword'];
                $searchKeywordIn = $formData['conditions']['searchKeywordIn'];

                $whereString .= $whereString != '' ? ' OR ' : ' (';

                $sp = '';
                foreach ($searchKeywordIn as $searchIn) {
                    $sp .= ($sp != '' ? ' OR ' : '') . $searchIn . ' LIKE :searchKeyword:';
                }

                $whereString .= $sp . ')';
                $bindParams['searchKeyword'] = '%' . $searchKeyword . '%';
            }

            /**
             * Optional Filter by tags
             */
            if (count($formData['conditions']['filterBy']) > 0) {
                $filterby = $formData['conditions']['filterBy'];

                foreach ($filterby as $k => $v) {
                    if ($v) {
                        $whereString .= ($whereString != '' ? ' AND ' : '') . $k . ' = :' . $k . ':';
                        $bindParams[$k] = $v;

                        switch (gettype($v)) {
                            case 'string':
                                $bindTypeParams[$k] =  \PDO::PARAM_STR;
                                break;

                            default:
                                $bindTypeParams[$k] = \PDO::PARAM_INT;
                                break;
                        }
                    }
                }
            }

            if (strlen($whereString) > 0 && count($bindParams) > 0) {
                $formData['conditions'] = [
                    [
                        $whereString,
                        $bindParams,
                        $bindTypeParams
                    ]
                ];
            } else {
                $formData['conditions'] = '';
            }
        }

        $params = [
            'models' => $model,
            'columns' => $formData['columns'],
            'conditions' => $formData['conditions'],
            'order' => [$model . '.' . $formData['orderBy'] .' '. $formData['orderType']]
        ];

        $builder = new PhBuilder($params);
        $paginatorKey = 'builder';

        if ($cache) {
            // Cache key
            $key = 'model.paginate.' . self::_createKey($params);

            // Check cache
            $cacheService = self::getStaticDI()->get('cacheData');
            $simpleResultSet = $cacheService->get($key);

            if ($simpleResultSet) {
                $model = '\Phalcon\Paginator\Adapter\Model';
                $builder = $simpleResultSet;
                $paginatorKey = 'data';
            } else {
                $model = '\Phalcon\Paginator\Adapter\QueryBuilder';

                // Set cache
                $builder->getQuery()->cache([
                    'key' => $key,
                    'lifetime' => $lifetime // seconds, 5 minutes
                ])->execute();
            }
        } else {
            $model = '\Phalcon\Paginator\Adapter\QueryBuilder';
        }

        // Create paginator object
        $paginator = new $model([
            $paginatorKey => $builder,
            'limit' => $limit,
            'page' => $offset
        ]);

        return $paginator->getPaginate();
    }

    /**
     * Returns the DI container
     */
    public function getDI()
    {
        return DI\FactoryDefault::getDefault();
    }

    /**
     * Returns the static DI container
     */
    public static function getStaticDI()
    {
        return DI\FactoryDefault::getDefault();
    }

    // Override findFirst function to create cache
    public static function findFirst($parameters = null)
    {
        if (isset($parameters['cache'])) {
            // Cache key
            $key = 'model.first.' . self::_createKey($parameters);

            $parameters['cache'] = [
                'key' => $key,
                'lifetime' => $parameters['cache']['lifetime'],
            ];
        }

       return parent::findFirst($parameters);
    }

    // Override find function to create cache
    public static function find($parameters = null)
    {
        if (isset($parameters['cache'])) {
            // Cache key
            $key = 'model.find.' . self::_createKey($parameters);

            $parameters['cache'] = [
                'key' => $key,
                'lifetime' => $parameters['cache']['lifetime'],
            ];
        }

       return parent::find($parameters);
    }

    // Override count function to create cache
    public static function count($parameters = null)
    {
        if (isset($parameters['cache'])) {
            // Cache key
            $key = 'model.count.' . self::_createKey($parameters);

            $parameters['cache'] = [
                'key' => $key,
                'lifetime' => $parameters['cache']['lifetime'],
            ];
        }

       return parent::count($parameters);
    }

    public static function _createKey($params): string
    {
        // Cache key
        $key = str_replace('\\', '', get_called_class()) . '.' . md5(json_encode($params)) . '.cache';

        return $key;
    }
}