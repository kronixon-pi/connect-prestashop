# The FastCGI request of every location in default.conf ($script: the PHP file under $shop).
include fastcgi_params;
fastcgi_param SCRIPT_FILENAME $shop$script;
fastcgi_param SCRIPT_NAME $script;
fastcgi_param PATH_INFO $path_info;
fastcgi_param DOCUMENT_ROOT $shop;
fastcgi_param HTTP_PROXY "";
fastcgi_pass 127.0.0.1:9000;
fastcgi_read_timeout 120s;
fastcgi_buffer_size 64k;
fastcgi_buffers 16 64k;
