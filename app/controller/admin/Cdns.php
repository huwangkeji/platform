<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use app\service\CdnService;
use think\facade\Db;
use think\response\Json;

/**
 * CDN 节点管理（海外加速/多节点分发）
 */
class Cdns extends BaseController
{
    /**
     * 节点列表
     */
    public function index(): Json
    {
        $list = Db::name('cdn_nodes')->order('weight', 'desc')->order('id', 'asc')->select()->toArray();
        return success(['list' => $list]);
    }

    /**
     * 创建节点
     * POST /api/v1/admin/cdns
     * body: name, region, host, weight, secret_key?
     */
    public function create(): Json
    {
        $name = trim((string) $this->request->post('name', ''));
        $host = trim((string) $this->request->post('host', ''));
        $region = strtolower((string) $this->request->post('region', 'global'));
        if ($name === '' || $host === '') {
            return fail(10003, 'name/host 不能为空');
        }
        if (!in_array($region, ['cn', 'hk', 'us', 'sg', 'eu', 'global'], true)) {
            $region = 'global';
        }
        if (strpos($host, '://') === false) {
            $host = 'https://' . $host;
        }
        $now = datetime_now();
        $id  = Db::name('cdn_nodes')->insertGetId([
            'name'       => $name,
            'region'     => $region,
            'host'       => $host,
            'weight'     => max(1, (int) $this->request->post('weight', 1)),
            'secret_key' => (string) $this->request->post('secret_key', '') !== '' ? (string) $this->request->post('secret_key') : null,
            'status'     => (int) $this->request->post('status', 1) === 1 ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->opLog('cdn', 'create', $id, '新增CDN节点 ' . $name . '（' . $region . '）');
        return success(['id' => $id], '创建成功');
    }

    /**
     * 更新节点
     */
    public function update(): Json
    {
        $id = (int) $this->request->param('id');
        $n  = Db::name('cdn_nodes')->where('id', $id)->find();
        if (!$n) {
            throw BizException::notFound('节点不存在');
        }
        $data = ['updated_at' => datetime_now()];
        $name = trim((string) $this->request->put('name', ''));
        if ($name !== '') {
            $data['name'] = $name;
        }
        $host = trim((string) $this->request->put('host', ''));
        if ($host !== '') {
            if (strpos($host, '://') === false) {
                $host = 'https://' . $host;
            }
            $data['host'] = $host;
        }
        $region = strtolower((string) $this->request->put('region', ''));
        if ($region !== '' && in_array($region, ['cn', 'hk', 'us', 'sg', 'eu', 'global'], true)) {
            $data['region'] = $region;
        }
        $weight = (int) $this->request->put('weight', 0);
        if ($weight > 0) {
            $data['weight'] = $weight;
        }
        $secret = (string) $this->request->put('secret_key', '');
        if ($secret !== '') {
            $data['secret_key'] = $secret;
        }
        $status = (int) $this->request->put('status', -1);
        if ($status === 0 || $status === 1) {
            $data['status'] = $status;
        }
        Db::name('cdn_nodes')->where('id', $id)->update($data);
        $this->opLog('cdn', 'update', $id, '更新CDN节点 ' . $n['name']);
        return success(null, '更新成功');
    }

    /**
     * 删除节点
     */
    public function delete(): Json
    {
        $id = (int) $this->request->param('id');
        $n  = Db::name('cdn_nodes')->where('id', $id)->find();
        if (!$n) {
            throw BizException::notFound('节点不存在');
        }
        Db::name('cdn_nodes')->where('id', $id)->delete();
        $this->opLog('cdn', 'delete', $id, '删除CDN节点 ' . $n['name']);
        return success(null, '删除成功');
    }

    /**
     * 测试节点连通性（生成签名URL）
     */
    public function test(): Json
    {
        $node = CdnService::pickNode((string) $this->request->get('region', 'global'));
        return success([
            'node'     => $node,
            'signed'   => $node ? CdnService::signedUrl($node, 'ping.txt', 60) : null,
        ], $node ? 'ok' : '暂无启用节点');
    }
}