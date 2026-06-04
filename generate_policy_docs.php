<?php
/**
 * 自动从 subsidy.policy_info 拉取最新上架政策，并为每条政策生成 Word 文档。
 *
 * 用法：php generate_policy_docs.php
 */

$config = [
    'host' => '19.tcp.vip.cpolar.cn',
    'user' => 'root',
    'password' => 'Kamfu168#',
    'port' => 12732,
    'charset' => 'utf8mb4',
    'dbname' => 'subsidy',
];

$companyProfile = [
    'name' => '深圳金赋科技有限公司',
    'positioning' => '利用人工智能技术向客户提供政策数据服务的科技公司',
    'mission' => '依托补贴数据平台的技术能力，通过补贴平台让政府政策信息更有效地触达企业，助力企业发展',
    'slogan' => '智能匹配政策，助力企业成长',
    'assets' => '1100万+全国四级政府公开政策数据，覆盖国家、省、市、区/县四级扶持类政策信息，并按八大类、100多个细分产业精细化归类标签化。',
    'credentials' => '国家高新技术企业，拥有7项授权发明专利、32+项软件著作权、107份数据知识产权登记证书，是两项数据资产相关团体标准核心起草单位。',
    'contacts' => '官网：www.szkamfu.com.cn；热线：0755-26510986；邮箱：szkf@kamfu.com.cn；公众号：「金赋数据」「金赋补贴宝」。',
];

function quoteIdentifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function pickColumn(array $columns, array $candidates, array $contains = []): ?string
{
    $lowerMap = [];
    foreach ($columns as $column) {
        $lowerMap[strtolower($column)] = $column;
    }
    foreach ($candidates as $candidate) {
        $key = strtolower($candidate);
        if (isset($lowerMap[$key])) {
            return $lowerMap[$key];
        }
    }
    foreach ($columns as $column) {
        $lower = strtolower($column);
        foreach ($contains as $needle) {
            if (str_contains($lower, strtolower($needle))) {
                return $column;
            }
        }
    }
    return null;
}

function normalizeText(?string $text): string
{
    $text = trim((string) $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return $text;
}

function excerpt(?string $text, int $length = 260): string
{
    $text = normalizeText($text);
    if (mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length, 'UTF-8') . '……';
}

function sanitizeWindowsFilename(string $name): string
{
    $name = preg_replace('/[<>:"\/\\|?*\x00-\x1F]/u', '', $name) ?? $name;
    $name = trim($name);
    $name = rtrim($name, ". ");
    if ($name === '') {
        $name = '未命名政策';
    }
    $reserved = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
    if (in_array(strtoupper($name), $reserved, true)) {
        $name = '_' . $name;
    }
    return mb_substr($name, 0, 120, 'UTF-8');
}

function uniquePath(string $directory, string $baseName): string
{
    $path = $directory . DIRECTORY_SEPARATOR . $baseName . '.docx';
    $index = 2;
    while (file_exists($path)) {
        $path = $directory . DIRECTORY_SEPARATOR . $baseName . '_' . $index . '.docx';
        $index++;
    }
    return $path;
}

function xmlEscape(string $text): string
{
    return htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function paragraphXml(string $text, string $style = 'Normal'): string
{
    $styleXml = $style === 'Normal' ? '' : '<w:pPr><w:pStyle w:val="' . xmlEscape($style) . '"/></w:pPr>';
    $runs = '';
    $parts = preg_split('/(\n)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $part) {
        if ($part === "\n") {
            $runs .= '<w:r><w:br/></w:r>';
        } elseif ($part !== '') {
            $runs .= '<w:r><w:t xml:space="preserve">' . xmlEscape($part) . '</w:t></w:r>';
        }
    }
    return '<w:p>' . $styleXml . $runs . '</w:p>';
}

function createDocx(string $path, string $title, array $paragraphs): void
{
    $body = paragraphXml($title, 'Title');
    foreach ($paragraphs as $paragraph) {
        $body .= paragraphXml($paragraph['text'], $paragraph['style'] ?? 'Normal');
    }

    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:sz w:val="24"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/></w:pPr><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:sz w:val="30"/></w:rPr></w:style>'
        . '</w:styles>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('无法创建文档：' . $path);
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('word/styles.xml', $stylesXml);
    $zip->close();
}

function buildArticle(string $title, string $coreContent, array $row, array $companyProfile): array
{
    $department = $row['department'] ?? $row['dept_name'] ?? $row['publish_department'] ?? $row['source'] ?? '主管部门';
    $publishTime = $row['publish_time'] ?? $row['pub_time'] ?? $row['release_time'] ?? $row['publish_date'] ?? $row['created_at'] ?? '';
    $deadline = $row['deadline'] ?? $row['end_time'] ?? $row['apply_end_time'] ?? $row['申报截止时间'] ?? '';
    $amount = $row['amount'] ?? $row['subsidy_amount'] ?? $row['max_amount'] ?? $row['support_amount'] ?? '';

    $paragraphs = [];
    $paragraphs[] = ['style' => 'Normal', 'text' => "{$title}正式发布。作为补贴数据平台的运营主体，{$companyProfile['name']}基于{$companyProfile['assets']}，为企业梳理本政策的关注重点、适配对象与申报准备方向，帮助企业更高效判断自身是否具备申报价值。"];
    $paragraphs[] = ['style' => 'Heading1', 'text' => '一、政策核心信息'];
    $paragraphs[] = ['style' => 'Normal', 'text' => '政策标题：' . $title . "\n发布单位：" . normalizeText((string) $department) . "\n发布时间：" . normalizeText((string) $publishTime) . ($deadline !== '' ? "\n申报截止：" . normalizeText((string) $deadline) : '') . ($amount !== '' ? "\n支持额度：" . normalizeText((string) $amount) : '')];
    $paragraphs[] = ['style' => 'Heading1', 'text' => '二、政策要点解读'];
    $paragraphs[] = ['style' => 'Normal', 'text' => $coreContent !== '' ? $coreContent : '本条政策的核心内容暂未提供结构化摘要，建议企业结合政策原文进一步核验申报范围、支持方式、材料要求和时间节点。'];
    $paragraphs[] = ['style' => 'Heading1', 'text' => '三、企业适配建议'];
    $paragraphs[] = ['style' => 'Normal', 'text' => '从政策服务经验看，企业应优先核对主体资格、经营区域、所属行业、营收或研发投入、知识产权、财务合规、项目实施进度等关键条件。制造业、科技型企业、专精特新培育对象、软件与信息服务企业、跨境电商与外贸企业可重点关注政策是否涉及技术改造、研发创新、产业化落地、市场拓展、资质认定或稳增长奖励。'];
    $paragraphs[] = ['style' => 'Heading1', 'text' => '四、申报准备清单'];
    $paragraphs[] = ['style' => 'Normal', 'text' => '建议企业提前准备营业执照、纳税与社保记录、财务报表、专项审计或研发费用归集资料、项目合同与发票、知识产权证明、荣誉资质、项目实施佐证、企业信用承诺书等材料。若政策设置网上填报、专家评审、现场核查或答辩环节，还应同步完善项目实施说明、预算测算、绩效目标和风险控制说明。'];
    $paragraphs[] = ['style' => 'Heading1', 'text' => '五、深圳金赋如何提供支持'];
    $paragraphs[] = ['style' => 'Normal', 'text' => "{$companyProfile['name']}是一家{$companyProfile['positioning']}，坚持“{$companyProfile['slogan']}”。公司依托补贴数据平台和补贴平台，为企业提供政策匹配、资格评估、申报规划、材料编写指导、流程管控、答辩辅导和合规审查等服务。{$companyProfile['credentials']}"];
    $paragraphs[] = ['style' => 'Heading1', 'text' => '六、结语'];
    $paragraphs[] = ['style' => 'Normal', 'text' => "政策申报强调时效性、材料完整性和项目真实性。近期有新政策申报需求的企业，可通过深圳金赋旗下补贴平台免费做资质评估，借助AI算法生成专属补贴推荐列表。{$companyProfile['contacts']}"];
    return $paragraphs;
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['dbname'], $config['charset']);
try {
    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $exception) {
    fwrite(STDERR, '数据库连接失败：' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, '请确认网络/DNS 可访问 19.tcp.vip.cpolar.cn，并确认端口为 12732。' . PHP_EOL);
    exit(1);
}

$columns = [];
foreach ($pdo->query('SHOW COLUMNS FROM policy_info') as $column) {
    $columns[] = $column['Field'];
}

$titleColumn = pickColumn($columns, ['title', 'policy_title', 'name', 'policy_name'], ['title', 'name']);
$coreColumn = pickColumn($columns, ['core_content', 'core', 'summary', 'abstract', 'content', 'policy_content', 'main_content', 'detail'], ['core', 'summary', 'content']);
$publishColumn = pickColumn($columns, ['publish_time', 'pub_time', 'release_time', 'publish_date', 'release_date', 'created_at', 'create_time', 'update_time'], ['publish', 'release', 'time', 'date']);

if ($titleColumn === null || $publishColumn === null) {
    throw new RuntimeException('无法识别标题或发布时间字段；当前字段：' . implode(', ', $columns));
}

$sql = 'SELECT * FROM policy_info WHERE ' . quoteIdentifier('up_down') . ' = 1 ORDER BY ' . quoteIdentifier($publishColumn) . ' DESC LIMIT 10';
$rows = $pdo->query($sql)->fetchAll();

if (!$rows) {
    echo "未查询到 up_down=1 的政策数据。\n";
    exit(0);
}

$outputDir = getcwd();
$generated = [];

echo "最新10条政策明细清单：\n";
foreach ($rows as $index => $row) {
    $title = normalizeText((string) ($row[$titleColumn] ?? '未命名政策'));
    $coreContent = $coreColumn === null ? '' : normalizeText((string) ($row[$coreColumn] ?? ''));
    $publishedAt = normalizeText((string) ($row[$publishColumn] ?? ''));

    echo sprintf("%02d. 标题：%s\n", $index + 1, $title);
    echo sprintf("    发布时间：%s\n", $publishedAt);
    echo sprintf("    核心内容：%s\n", excerpt($coreContent, 500));

    $fileName = sanitizeWindowsFilename($title);
    $path = uniquePath($outputDir, $fileName);
    createDocx($path, $title, buildArticle($title, $coreContent, $row, $companyProfile));
    $generated[] = basename($path);
}

echo "\n生成的Word文档列表：\n";
foreach ($generated as $file) {
    echo '- ' . $file . "\n";
}
