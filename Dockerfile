# # Sử dụng image chính thức của WordPress
# FROM wordpress:latest

# # Sao chép các theme tùy chỉnh vào thư mục themes của WordPress
# COPY ./wp-content /var/www/html/wp-content/themes/

# # COPY ./my-plugin /var/www/html/wp-content/plugins/my-plugin

# # Chạy lệnh cập nhật và cài đặt gói bổ sung nếu cần
# RUN apt-get update && apt-get install -y curl


# Sử dụng image chính thức của WordPress
FROM wordpress:latest

# Sao chép toàn bộ nội dung của dự án WordPress vào thư mục gốc của WordPress trong container
COPY . /var/www/html/

# Chạy lệnh cập nhật và cài đặt gói bổ sung nếu cần
RUN apt-get update && apt-get install -y curl

# Đảm bảo quyền sở hữu và quyền truy cập của thư mục wp-content
RUN chown -R www-data:www-data /var/www/html/wp-content \
    && chmod -R 755 /var/www/html/wp-content

