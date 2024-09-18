# sql 基建

```markdown
/**
* author: hewro
* version: 1.0
*/
```

- `/driver/sql` 为数据库表创建、查询等与数据库语句相关
  - `file` 为具体的数据库语句文件
- `/dao/` 为数据库查询层
  - `sql_base` 为基础业务，传入业务名称，即可根据当前typecho的数据库类型创建/检查对应的数据库
- `controller` 复杂的面向业务的数据库组装层


# 增加一个新的表
- `/driver/sql/file` 创建不同数据库对应的sql
- `/driver/dao` 增加一个业务php 继承 sqlbase，封装一些数据库操作
- `/driver/controller` 业务层的数据库查询
- 真正的业务直接使用controller 中的对象即可

# TODO

- [x] driver/sql 三个数据库基类完成，主要是读取对应的sql 文件，创建数据库
- [x] 测试cache 表的功能是否正常，替换原来的 driver/下的四个文件
- [ ] 完成tag 业务的增删查改的接口
- [ ] 完成前端对tag接口的调用封装
- [ ] 前断UI、tag内容解析，提交、提交前解析等内容
