# MySQL 백업 스케줄러

Windows 작업 스케줄러에서 실행하는 PHP CLI 도구입니다. 여러 MySQL 서버의 데이터베이스를 SQL 파일로 백업하고, 선택적으로 전용 로컬 MySQL 서버에 복원합니다. 실행 결과와 오류는 파일에 기록하며 Slack 알림도 설정할 수 있습니다.

**기본값은 SQL 파일 백업만 수행합니다.** 로컬 복원은 `restore-enabled = true`와 복원 허용 목록을 명시해야 실행됩니다. 복원을 켜면 대상 데이터베이스를 `DROP DATABASE`로 삭제한 후 다시 만듭니다.

## 설치

1. Windows에 PHP **8.3 이상 CLI**를 설치하고 `php.exe`를 PATH에 등록합니다. Composer는 필요하지 않습니다. Slack 알림을 사용하려면 PHP cURL 확장과 신뢰할 수 있는 CA 인증서 설정이 필요합니다.
2. 원격 서버 버전과 호환되는 MySQL 8.0 이상 클라이언트(`mysql.exe`, `mysqldump.exe`)를 설치합니다. PATH에 등록하거나 `settings.ini`에 실행 파일의 절대 경로를 지정합니다. 백업 파일만 만들 때는 로컬 MySQL 서버가 필요하지 않습니다. 이 도구는 MySQL용이며 MariaDB 클라이언트의 옵션·서버 식별 방식과는 호환을 보장하지 않습니다.
3. 예제 설정을 복사하고 접속 정보와 백업 대상을 수정합니다.

```powershell
Copy-Item .\database.ini.example .\database.ini
Copy-Item .\settings.ini.example .\settings.ini
```

4. 설정 검증을 실행합니다.

```powershell
php.exe .\MySQLDump.php --check
```

`--check`는 설정만 검증합니다. DB 접속, Slack 요청, 디렉터리·로그·백업 파일 생성은 하지 않습니다. 따라서 연결 가능 여부, 계정 권한, 실제 덤프와 복원 성공 여부는 별도 실행으로 확인해야 합니다.

## 실행

```powershell
# 기본 설정 폴더: MySQLDump.php가 있는 폴더
php.exe .\MySQLDump.php

# 별도 설정 폴더: 예약 실행에는 절대 경로 권장
php.exe .\MySQLDump.php --config-dir="D:\MySQL Backups"

# 설정만 검증
php.exe .\MySQLDump.php --config-dir="D:\MySQL Backups" --check

# 사용법
php.exe .\MySQLDump.php --help
```

설정 폴더에는 `database.ini`, `settings.ini`가 있어야 합니다. 백업, 실행 기록, 잠금 파일도 이 폴더 아래에 저장됩니다. 기본 설정 폴더는 현재 작업 디렉터리가 아니라 스크립트 위치를 기준으로 하므로, 작업 스케줄러용으로 PHP 코드의 경로를 수정할 필요가 없습니다. `--config-dir`에 상대 경로를 전달하면 현재 작업 디렉터리를 기준으로 해석합니다.

| 종료 코드 | 의미 |
| --- | --- |
| `0` | 전체 성공 또는 도움말·설정 검증 성공 |
| `1` | 설정, 백업, 복원, 파일 보관·정리, 로그 또는 Slack 알림 오류 |
| `2` | 잘못된 CLI 인자 |
| `3` | 같은 설정 폴더에서 이미 실행 중 |

## 원격 데이터베이스 설정

`database.ini`의 섹션 하나가 원본 서버 하나를 나타냅니다. 실제 형식은 [database.ini.example](database.ini.example)을 참고하세요.

```ini
[source01]
host = "db-host.internal"
port = 3306
username = "backup_reader"
password = "replace-with-your-password"
database[0] = "app"
database[1] = "analytics"
ignore-table[1] = "temporary_report,import_staging"
```

- 섹션 이름은 ASCII 영문자·숫자로 시작하는 1~64글자이며, 나머지 글자에는 `_`, `-`도 사용할 수 있습니다. 대소문자만 다른 원본 이름도 중복으로 취급합니다.
- `host`, `username`, `password`, 백업할 `database[index]`를 설정합니다. `port` 기본값은 `3306`입니다.
- 데이터베이스·테이블 이름은 ASCII 영문자, 숫자, `_`, `$`, `-`로 구성하며 64글자 이하여야 합니다. 첫 글자는 영문자, 숫자 또는 `_`여야 합니다.
- `ignore-table[index]`는 같은 인덱스의 데이터베이스에서 제외할 테이블을 쉼표로 나열합니다. 생략할 수 있습니다. 제외된 테이블은 구조와 데이터 모두 백업하지 않습니다.
- 설정은 `INI_SCANNER_RAW`로 읽습니다. 이전 코드처럼 `^`를 `^^`로 바꾸는 셸 이스케이프는 하지 않습니다. 바깥쪽 큰따옴표는 제거하지만 값 안의 백슬래시는 그대로 유지하므로 임의로 `\\`나 `\"`로 바꾸지 않습니다. 예를 들어 실제 암호가 `a"b\c^&`이면 `password = "a"b\c^&"`로 작성합니다. 작은따옴표는 이 모드에서 값의 일부로 취급됩니다.
- 설정 값은 한 줄로 작성합니다. 줄바꿈이 포함된 암호는 이 INI 형식에서 지원하지 않습니다.

백업 계정은 필요한 데이터베이스에만 접근하는 전용 계정을 사용하세요. 테이블, 뷰, 트리거, 이벤트, 저장 루틴을 읽는 데 필요한 권한은 MySQL 버전과 서버 설정에 따라 다릅니다. `root/root`를 공통 계정으로 사용하지 말고 [해당 MySQL 버전의 mysqldump 문서](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html)를 확인하세요.

## 실행·알림 설정

전체 예시는 [settings.ini.example](settings.ini.example)에 있습니다.

| 키 | 기본값 또는 역할 |
| --- | --- |
| `mysql-binary` | `mysql.exe`; PATH의 실행 파일 또는 실행 파일 경로 |
| `mysqldump-binary` | `mysqldump.exe`; PATH의 실행 파일 또는 실행 파일 경로 |
| `restore-enabled` | `false`; 로컬 복원 여부 |
| `localhost-host` | `127.0.0.1`; 로컬 루프백 주소만 허용 |
| `localhost-port` | `3306` |
| `localhost-username` | 복원용 전용 계정; 복원할 때 설정 |
| `localhost-password` | 복원용 계정의 암호; 복원할 때 설정 |
| `restore-databases[]` | 복원을 허용할 데이터베이스 목록; 복원할 때 필수 |
| `retention-days` | `30`; 성공한 실행의 자동 보관 기간(일) |
| `process-timeout` | `3600`; 외부 프로세스 제한 시간(초) |
| `slack-url` | 선택 사항; 생략하면 Slack 알림 비활성화 |
| `slack-timeout` | `10`; Slack 요청 한 번의 제한 시간(초) |
| `slack-ca-bundle` | 선택 사항; 신뢰할 CA 인증서 묶음 파일 |

실행 파일 경로에 공백이 있으면 INI 값 전체를 따옴표로 감쌉니다. 실행 파일만 지정하고, 이 값에 옵션을 덧붙이지 않습니다. 디렉터리 구분자가 있는 상대 실행 파일 경로와 상대 CA 파일 경로는 설정 폴더를 기준으로 해석합니다.

```ini
mysql-binary = "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe"
mysqldump-binary = "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqldump.exe"
```

Slack 웹훅은 HTTPS의 `hooks.slack.com/services/...` 또는 `hooks.slack-gov.com/services/...` URL만 허용합니다. 인증서 검증을 수행하며, 필요한 경우 `slack-ca-bundle`에 CA 파일의 절대 경로를 지정합니다. 알림이 실패해도 백업 작업은 계속하지만 최종 종료 코드는 `1`입니다.

## 로컬 복원

전용 로컬 MySQL 서버와 복원 전용 계정을 준비하고 다음처럼 설정합니다.

```ini
restore-enabled = true
localhost-host = "127.0.0.1"
localhost-port = 3306
localhost-username = "backup_restore"
localhost-password = "replace-with-your-password"
restore-databases[] = "app"
restore-databases[] = "analytics"
```

복원을 켜려면 원격 설정의 **모든** 백업 대상 데이터베이스가 허용 목록에 대소문자까지 동일하게 있어야 합니다. 서로 다른 원본을 포함해 전체 작업에서 같은 데이터베이스 이름을 중복 사용할 수 없으며, 대소문자만 다른 이름도 중복으로 취급합니다. 대상 이름은 원본 이름과 같으며, 허용 목록은 DB 이름을 바꾸는 매핑 기능이 아닙니다. `mysql`, `sys`, `information_schema`, `performance_schema` 시스템 데이터베이스는 복원할 수 없습니다.

실행 전 원격·로컬 서버의 `server_uuid`를 비교하여 같은 서버로 복원하는 것을 거부합니다. 로컬 접속 주소 역시 루프백만 허용합니다. 이러한 검사와 별개로 복원용 서버와 계정은 운영 데이터베이스와 분리하여 구성하세요.

**복원은 기존 데이터베이스 전체를 삭제하고 다시 만드는 작업입니다.** `ignore-table`로 제외한 테이블도 기존 로컬 데이터베이스에 있었다면 함께 삭제되며, 덤프에 없으므로 다시 생성되지 않습니다. 기존 로컬 변경 사항 역시 보존되지 않습니다. 복원 중 실패하면 일부 SQL만 적용된 상태가 될 수 있고 자동 롤백은 제공하지 않습니다. 허용 목록의 데이터베이스에 대해서만 삭제·생성·복원에 필요한 권한을 부여하세요.

## 백업 파일과 보관

```text
설정 폴더/
├─ database.ini
├─ settings.ini
├─ .mysqldump.lock
├─ logs/
│  └─ scheduler.log
└─ sql_storage/
   └─ YYYYMMDD/
      └─ <timestamp-random-run-id>/
         ├─ dump-001-source01-app.sql
         └─ manifest.json
```

- 실행마다 고유한 폴더를 생성하므로 같은 날 재실행해도 이전 백업을 덮어쓰지 않습니다. SQL 파일명에도 작업 순번을 넣어 원본·DB 이름 조합의 충돌을 방지합니다.
- 덤프는 `.sql.partial`에 쓰고 성공 시 `.sql`로 보관을 확정한 뒤 복원합니다. 덤프 실패 파일은 `.sql.partial`로 남기며 복원하지 않습니다. 파일 보관 실패도 작업 실패에 포함합니다.
- 각 실행의 `manifest.json`과 `logs/scheduler.log`에 작업 결과를 기록합니다. SQL 파일과 함께 실행 기록을 확인하세요.
- 같은 설정 폴더에서 중복 실행하면 `.mysqldump.lock`을 사용해 두 번째 실행을 거부합니다. 실행 중 잠금 파일을 임의로 삭제하지 마세요.
- 자동 정리는 현재 실행의 백업·보관·활성화된 복원과 시작 알림이 모두 성공한 뒤 한 번만 수행합니다. 날짜 폴더 기준으로 보존 기간이 지났고 manifest에 전체 성공이 기록된 실행만 대상으로 합니다. 실패·불완전 실행, 이전 버전의 보관 파일, 기록에 없는 파일, 심볼릭 링크는 자동 삭제하지 않습니다. 기본 30일은 오늘을 포함한 최근 30개 날짜를 보존합니다.
- 실패 실행은 남아 있으므로 용량을 점검하고 내용을 확인한 뒤 수동으로 정리하세요. 이 도구는 백업 압축이나 별도 장치로의 복제를 제공하지 않습니다.

덤프에는 저장 프로시저·함수, 이벤트, 트리거가 포함되며 `--set-gtid-purged=OFF`를 사용합니다. `--single-transaction`은 InnoDB 데이터의 일관된 읽기를 위한 옵션입니다. 비트랜잭션 테이블이나 덤프 중 스키마 변경에는 제약이 있으며, 데이터베이스를 차례로 덤프하므로 여러 DB가 동일 시점의 스냅샷이라는 보장은 없습니다. 관련 제약은 [MySQL mysqldump 문서](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html)를 참고하세요.

## Windows 작업 스케줄러

1. 먼저 예약 실행에 사용할 Windows 계정으로 `--check`와 수동 백업을 수행합니다.
2. 작업 스케줄러에서 작업을 만들고 원하는 실행 주기를 설정합니다.
3. 동작의 프로그램/스크립트에는 `mysqldump.bat`의 절대 경로를 지정합니다. 별도 설정 폴더를 사용할 때 인수에 `--config-dir="D:\MySQL Backups"`를 넣습니다.
4. 새 인스턴스를 시작하지 않도록 중복 실행 정책을 설정합니다. 도구 자체도 설정 폴더별 잠금을 적용합니다.
5. 작업의 마지막 실행 결과와 `logs/scheduler.log`를 확인합니다.

배치 파일은 스크립트 위치를 `%~dp0`로 찾으므로 시작 위치를 맞추거나 `cd`를 추가할 필요가 없습니다. 전달받은 인수를 PHP로 넘기며 PHP 종료 코드를 그대로 반환합니다. 기본 인터프리터는 PATH의 `php.exe`입니다. 별도 PHP 실행 파일을 사용하려면 작업 실행 계정의 `PHP_BINARY` 환경변수에 절대 경로를 지정하세요.

```powershell
# 현재 PowerShell에서 배치 실행 시 사용할 PHP 지정 예시
$env:PHP_BINARY = 'C:\Tools\PHP 8.3\php.exe'
& .\mysqldump.bat --check
```

작업 스케줄러가 이 환경변수를 사용하려면 예약 작업을 실행하는 계정의 환경에도 설정해야 합니다. 환경변수 값 자체에는 따옴표를 넣지 않습니다.

## 설정과 파일 접근 권한

`database.ini`, `settings.ini`에는 암호와 웹훅이 들어갈 수 있고, SQL 파일에는 원본 데이터가 포함됩니다. 설정 폴더는 예약 작업 계정과 필요한 관리자만 접근할 수 있도록 Windows ACL을 제한하세요. 웹 서버의 공개 폴더에 두지 않습니다.

DB 암호는 명령행 인자로 전달하지 않고 접근 권한을 제한한 임시 MySQL 옵션 파일에 기록하며 실행 종료 시 삭제합니다. 설정 폴더, 임시 폴더, 백업 저장소에 필요한 읽기·쓰기 권한을 작업 실행 계정에 부여해야 합니다. 운영체제 강제 종료 등 비정상 종료 후에는 임시 파일과 실행 기록을 점검하세요.

실제 설정, SQL 백업, 로그, 잠금 파일, `.env` 파일은 `.gitignore`에서 제외합니다. 이미 Git에 추적된 비밀 파일은 `.gitignore`만 추가해도 제거되지 않으므로 별도로 추적을 해제하고 노출된 자격 증명을 교체하세요.

## 기존 버전에서 이행

1. 기존 작업 스케줄러 작업을 중지하고 진행 중인 실행이 끝났는지 확인합니다.
2. 기존 설정과 보관된 SQL 파일을 별도로 보존합니다.
3. PHP를 8.3 이상으로 준비하고 예제 설정에 맞춰 키와 데이터베이스 이름을 점검합니다. 비밀번호의 수동 셸 이스케이프(`^^` 등)는 실제 암호로 되돌립니다.
4. 예전 버전은 덤프 후 자동으로 로컬 복원을 수행했지만, 새 버전은 **복원이 기본 비활성화**입니다. 계속 복원하려면 `restore-enabled = true`, 로컬 접속 정보, `restore-databases[]`를 명시하고 원본 사이에 같은 DB명이 없는지 확인합니다.
5. PHP 코드의 `$rootPath`나 배치 파일 안의 고정 경로를 수정하던 방식 대신 `--config-dir`을 사용합니다. 예약 실행에는 절대 경로를 지정합니다.
6. `--check`, 전용 환경에서의 수동 백업·복원 검증, 예약 작업 순서로 확인합니다. 새 저장 구조는 실행별 하위 폴더와 manifest를 사용합니다. 이전 구조의 백업은 새 자동 정리 대상으로 간주하지 말고 별도로 보관·정리하세요.

## 개발 검증

```powershell
php.exe -l .\MySQLDump.php
php.exe .\tests\run.php
```

PHP 8.3.25 / Windows에서 문법 검사와 자동 테스트를 실행했습니다. MySQL 8.0.46 클라이언트의 옵션 파싱도 연결 없이 확인했습니다. 테스트는 실제 DB 연결과 네트워크 요청 없이 실행하도록 구성되어 있으며 실제 서버에서의 백업·복원 검증을 대체하지 않습니다. 배포 전 사용하는 서버 버전, 계정 권한, 데이터 크기에 맞춰 복원까지 확인하세요.
