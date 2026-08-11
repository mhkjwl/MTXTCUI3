欢迎使用 - Eecms内容管理系统V1.0（正式版）！
本系统采用PHP+MySQL技术开发
拥有独立的安装和后台系统
后台采用Bootstrap+AdminLTE框架
前台使用响应式界面，自适应各种屏幕
授权声明：Eecms是免费可商用的建站系统，

您出于自愿而使用Eecms，您必须了解使用Eecms的风险，Eecms不提供任何形式的使用担保，也不承担任何因使用Eecms而产生问题的相关责任。

Eecms不对使用本系统构建的网站的任何信息内容以及导致的任何版权纠纷和法律争议及后果承担责任。
重点：抓头本人不对使用本软件所构建网站中的文章、商品和其它任何信息承担责任，不管您通过任何渠道下载本软件，
您一旦开始安装本程序，即被视为完全理解并接受授权声明的各项条款。

注意事项：
1.本系统采用伪静态，若您的主机不支持伪静态请勿使用
2.若是Apache服务器端软件，您只需要开启伪静态功能，本系统已经为您配置好了
3.若是Nginx服务器端软件，您只需要按照以下伪静态规则配置伪静态
	rewrite ^/index.html$ /index.php;
	rewrite ^/about.html$ /about.php;
	rewrite ^/search.html$ /search.php;
	rewrite ^/apply.html$ /apply.php;
	rewrite ^/404.html$ /404.php;
	rewrite ^/sort/([1-9]+[0-9]*).html$ /sort.php?id=$1;
	rewrite ^/sort/([a-zA-Z]+).html$ /sort.php?alias=$1;
	rewrite ^/site-([1-9]+[0-9]*).html$ /site.php?id=$1;
	rewrite ^/([a-zA-Z]+).html$ /site.php?alias=$1;

软著登记号：2022SR0050653
