#!/bin/sh

PHP_BINARY="php"
BUILD_TYPE="pocketmine"  # 默认构建 PocketMine

# 解析命令行参数
while [ $# -gt 0 ]; do
    case "$1" in
        -p)
            if [ -n "$2" ]; then
                PHP_BINARY="$2"
                shift 2
            else
                echo "Error: -p requires an argument" >&2
                exit 1
            fi
            ;;
        --genisyspro)
            if [ "$BUILD_TYPE" != "pocketmine" ]; then
                echo "Error: Cannot specify both --genisys and --genisyspro" >&2
                exit 1
            fi
            BUILD_TYPE="genisyspro"
            shift
            ;;
        --genisysgt)
            if [ "$BUILD_TYPE" != "pocketmine" ]; then
                echo "Error: Cannot specify both --genisysgt" >&2
                exit 1
            fi
            BUILD_TYPE="genisysgt"
            shift
            ;;
        --genisys)
            if [ "$BUILD_TYPE" != "pocketmine" ]; then
                echo "Error: Cannot specify both --genisys and --genisyspro" >&2
                exit 1
            fi
            BUILD_TYPE="genisys"
            shift
            ;;
        --) # 结束选项解析
            shift
            break
            ;;
        -*)
            echo "Unknown option: $1" >&2
            exit 1
            ;;
        *) # 非选项参数，结束解析
            break
            ;;
    esac
done

# ----- 构建函数定义 -----

# 构建 GenisysPro（自带构建命令，无需 DevTools）
buildGenisysPro() {
    echo -e "\nversion\nms\nstop\n" | "$PHP_BINARY" -dphar.readonly=0 src/pocketmine/PocketMine.php --no-wizard --disable-ansi --disable-readline --debug.level=2
    if ls plugins/GenisysPro/GenisysPro*.phar >/dev/null 2>&1; then
        echo "Server phar created successfully."
    else
        echo "No phar created!"
        exit 1
    fi
}

buildGenisysGT() {
    echo -e "\nversion\nms\nstop\n" | "$PHP_BINARY" -dphar.readonly=0 src/pocketmine/PocketMine.php --no-wizard --disable-ansi --disable-readline --debug.level=2
    if ls plugins/GenisysGT/GenisysGT*.phar >/dev/null 2>&1; then
        echo "Server phar created successfully."
    else
        echo "No phar created!"
        exit 1
    fi
}

# 构建 PocketMine 或旧版 Genisys（都需要 DevTools 插件）
buildPocketMine() {
    wget -O plugins/DevTools.phar https://github.com/XiGuaM/DevTools/releases/download/1.11.1/DevTools.phar

    echo -e "\nversion\nmakeserver\nstop\n" | "$PHP_BINARY" -dphar.readonly=0 src/pocketmine/PocketMine.php --no-wizard --disable-ansi --disable-readline --debug.level=2
    if ls plugins/DevTools/PocketMine-MP*.phar >/dev/null 2>&1; then
        echo "Server phar created successfully."
    else
        echo "No phar created!"
        exit 1
    fi
}

# 旧版 Genisys 构建方式与 PocketMine 完全相同，直接复用
buildGenisys() {
    wget -O plugins/DevTools.phar https://github.com/XiGuaM/DevTools/releases/download/1.11.1/DevTools.phar

    echo -e "\nversion\nmakeserver\nstop\n" | "$PHP_BINARY" -dphar.readonly=0 src/pocketmine/PocketMine.php --no-wizard --disable-ansi --disable-readline --debug.level=2
    if ls plugins/DevTools/Genisys*.phar >/dev/null 2>&1; then
        echo "Server phar created successfully."
    else
        echo "No phar created!"
        exit 1
    fi
}

# ----- 根据 BUILD_TYPE 调用对应函数 -----

case "$BUILD_TYPE" in
    genisyspro)
        buildGenisysPro
        ;;
    genisysgt)
        buildGenisysGT
        ;;
    genisys)
        buildGenisys
        ;;
    pocketmine|*)
        buildPocketMine
        ;;
esac