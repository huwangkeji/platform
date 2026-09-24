<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\service\RetentionService;
use think\facade\Db;
use think\response\Json;

/**
 * 版本保留策略：配置自动归档/清理规则，手动触发清理
 */
class Retention extends BaseController
{
    /**
     * 策略列表（应用级 + 全局默认）
     */
    public function index(): Json
    {
        $list = Db::name('retention_policies')->order('app_id', 'asc')->select()->toArray();
        $appNames = Db::name('apps')->column('name', 'id');
        foreach ($list as &$p) {
            $p['app_name'] = (int) $p['app_id'] === 0 ? '（全局默认）' : ($appNames[$p['app_id']] ?? '');
        }
        unset($p);
        return success(['list' => $list]);
    }

    /**
     * 查看某应用生效的策略
     */
    public function read(): Json
    {
        $appId = (int) $this->request->param('id');
        $policy = RetentionService::policy($appId);
        $policy['app_id'] = $appId;
        return success($policy);
    }

    /**
     * 创建/更新策略
     * POST /api/v1/admin/retentions（app_id=0 表示全局默认）
     */
    public function create(): Json
    {
        $appId = (int) $this->request->post('app_id', 0);
        if ($appId < 0) {
            return fail(10003, 'app_id 不合法');
        }
        if ($appId > 0 && !Db::name('apps')->where('id', $appId)->find()) {
            return fail(10004, '应用不存在');
        }
        $keepCount = max(1, (int) $this->request->post('keep_count', 10));
        $maxAge    = max(7, (int) $this->request->post('max_age_days', 180));
        $archive   = (int) $this->request->post('auto_archive', 1) === 1 ? 1 : 0;
        $physDel   = (int) $this->request->post('physical_delete', 0) === 1 ? 1 : 0;
        $now       = datetime_now();

        $exists = Db::name('retention_policies')->where('app_id', $appId)->find();
        $data = [
            'keep_count'      => $keepCount,
            'max_age_days'    => $maxAge,
            'auto_archive'    => $archive,
            'physical_delete' => $physDel,
            'updated_at'      => $now,
        ];
        if ($exists) {
            Db::name('retention_policies')->where('id', (int) $exists['id'])->update($data);
            $id = (int) $exists['id'];
            $msg = '策略已更新';
        } else {
            $data['app_id']     = $appId;
            $data['status']     = 1;
            $data['created_at'] = $now;
            $id = (int) Db::name('retention_policies')->insertGetId($data);
            $msg = '策略已创建';
        }
        $this->opLog('retention', 'save', $id, '保存版本保留策略 app_id=' . $appId . ' keep=' . $keepCount . ' days=' . $maxAge . ' 归档=' . $archive . ' 物理删除=' . $physDel);
        return success(['id' => $id], $msg);
    }

    /**
     * 删除应用级策略（回退到全局默认）
     */
    public function delete(): Json
    {
        $id = (int) $this->request->param('id');
        $p  = Db::name('retention_policies')->where('id', $id)->find();
        if (!$p) {
            return fail(10004, '策略不存在');
        }
        if ((int) $p['app_id'] === 0) {
            return fail(10003, '全局默认策略不可删除，可通过接口重置');
        }
        Db::name('retention_policies')->where('id', $id)->delete();
        $this->opLog('retention', 'delete', $id, '删除保留策略 app_id=' . $p['app_id']);
        return success(null, '已删除，将回退到全局默认策略');
    }

    /**
     * 立即执行清理
     * POST /api/v1/admin/retentions/run?app_id=xxx
     */
    public function run(): Json
    {
        $appId = (int) $this->request->get('app_id', 0);
        $stats = RetentionService::run($appId > 0 ? $appId : null);
        $this->opLog('retention', 'run', $appId ?: 0, '手动执行版本归档清理：' . json_encode($stats, JSON_UNESCAPED_UNICODE));
        return success($stats, '清理完成');
    }
}